#!/usr/bin/env python3
"""Benchmark-only OpenAI Responses API adapter for ActivityCaptureExtraction."""

from __future__ import annotations

import argparse
import json
import os
import time
import urllib.error
import urllib.request
from pathlib import Path
from typing import Any

API_URL = "https://api.openai.com/v1/responses"

SYSTEM_PROMPT = """Tu extrais uniquement des candidats linguistiques à partir d'une courte demande française.
Tu ne produis jamais une décision métier, une autorisation, un identifiant interne, un UUID, un Household, une RRULE ou un fuseau horaire.
label_text contient un libellé d'activité court déduit du texte, ou null si aucun libellé fiable n'est extractible.
location_text contient uniquement un lieu explicitement exprimé, sinon null.
concerned_person_mentions contient uniquement les mentions textuelles de personnes explicitement présentes.
concerned_person_alternative vaut true uniquement si ces mentions sont présentées comme des alternatives ou un choix, par exemple avec « ou ».
responsibility_candidate vaut "SELF" seulement si la responsabilité à la première personne est explicite; sinon le référent textuel explicite; sinon null.
date_expression conserve l'expression de date ou de jour telle qu'exprimée, sans la transformer en vérité métier.
date_day, date_month et date_year extraient uniquement les composantes numériques d'une date calendrier explicitement exprimée; laisse-les à null pour une date relative ou un simple jour de semaine.
relative_day_offset exprime seulement un sens relatif explicite et simple, par exemple aujourd'hui=0, demain=1, après-demain=2; sinon null.
start_time_expression et end_time_expression contiennent uniquement les expressions horaires explicitement présentes; n'invente jamais une durée ou une heure de fin.
recurrence_expression contient uniquement le fragment exprimant une répétition; null signifie qu'aucune répétition n'est exprimée.
explicit_all_day_signal vaut true uniquement si le texte exprime clairement une activité toute la journée / journée entière.
explicit_timed_signal vaut true si le texte exprime explicitement une heure ou un caractère horaire.
N'émets aucun drapeau global ambiguous/unsupported: la clarification, l'identité, la récurrence supportée et les valeurs finales sont résolues par l'application."""

SCHEMA: dict[str, Any] = {
    "type": "object",
    "additionalProperties": False,
    "properties": {
        "label_text": {"type": ["string", "null"]},
        "location_text": {"type": ["string", "null"]},
        "concerned_person_mentions": {"type": "array", "items": {"type": "string"}},
        "concerned_person_alternative": {"type": "boolean"},
        "responsibility_candidate": {"type": ["string", "null"]},
        "date_expression": {"type": ["string", "null"]},
        "date_day": {"type": ["integer", "null"], "minimum": 1, "maximum": 31},
        "date_month": {"type": ["integer", "null"], "minimum": 1, "maximum": 12},
        "date_year": {"type": ["integer", "null"], "minimum": 1970, "maximum": 2200},
        "relative_day_offset": {"type": ["integer", "null"], "minimum": -366, "maximum": 366},
        "start_time_expression": {"type": ["string", "null"]},
        "end_time_expression": {"type": ["string", "null"]},
        "recurrence_expression": {"type": ["string", "null"]},
        "explicit_all_day_signal": {"type": "boolean"},
        "explicit_timed_signal": {"type": "boolean"},
    },
    "required": [
        "label_text",
        "location_text",
        "concerned_person_mentions",
        "concerned_person_alternative",
        "responsibility_candidate",
        "date_expression",
        "date_day",
        "date_month",
        "date_year",
        "relative_day_offset",
        "start_time_expression",
        "end_time_expression",
        "recurrence_expression",
        "explicit_all_day_signal",
        "explicit_timed_signal",
    ],
}


def user_prompt(case: dict[str, Any]) -> str:
    return "\n".join(
        [
            "Contexte synthétique/local uniquement.",
            f"Instant UTC figé: {case['context_utc']}",
            f"Fuseau applicatif connu: {case['timezone']}",
            f"Demande: «{case['text']}»",
            "Retourne uniquement les candidats linguistiques demandés par le schéma.",
        ]
    )


def output_text(response: dict[str, Any]) -> str:
    for item in response.get("output", []):
        if item.get("type") != "message":
            continue
        for content in item.get("content", []):
            if content.get("type") == "output_text" and isinstance(content.get("text"), str):
                return content["text"]
            if content.get("type") == "refusal":
                raise RuntimeError("OPENAI_REFUSAL")
    raise RuntimeError("OPENAI_OUTPUT_TEXT_MISSING")


def safe_error(exc: BaseException) -> str:
    if isinstance(exc, urllib.error.HTTPError):
        return f"HTTP_{exc.code}"
    if isinstance(exc, urllib.error.URLError):
        reason = type(exc.reason).__name__ if exc.reason is not None else "UNKNOWN"
        return f"URL_ERROR_{reason}"
    name = type(exc).__name__.upper()
    if "TIMEOUT" in name:
        return "TIMEOUT"
    return name


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--model", required=True, choices=["gpt-5.6-sol", "gpt-5.6-terra"])
    parser.add_argument("--fixture", required=True)
    parser.add_argument("--output", required=True)
    args = parser.parse_args()

    api_key = os.environ.get("OPENAI_API_KEY", "")
    if not api_key:
        raise SystemExit("OPENAI_API_KEY_PRESENT=NO")

    fixture = json.loads(Path(args.fixture).read_text(encoding="utf-8"))
    if not isinstance(fixture, list) or len(fixture) != 11:
        raise SystemExit("Frozen V2 fixture must contain exactly 11 cases.")

    results: list[dict[str, Any]] = []
    for case in fixture:
        payload = {
            "model": args.model,
            "reasoning": {"effort": "none"},
            "tools": [],
            "background": False,
            "store": False,
            "input": [
                {"role": "system", "content": [{"type": "input_text", "text": SYSTEM_PROMPT}]},
                {"role": "user", "content": [{"type": "input_text", "text": user_prompt(case)}]},
            ],
            "text": {
                "format": {
                    "type": "json_schema",
                    "name": "activity_capture_extraction",
                    "strict": True,
                    "schema": SCHEMA,
                }
            },
        }

        started = time.perf_counter()
        extraction = None
        response_id = None
        usage = {"input_tokens": 0, "cached_input_tokens": 0, "output_tokens": 0}
        error_class = None
        status = None
        try:
            request = urllib.request.Request(
                API_URL,
                data=json.dumps(payload, ensure_ascii=False).encode("utf-8"),
                headers={
                    "Authorization": f"Bearer {api_key}",
                    "Content-Type": "application/json",
                },
                method="POST",
            )
            with urllib.request.urlopen(request, timeout=120) as response:
                decoded = json.loads(response.read().decode("utf-8"))
            response_id = decoded.get("id")
            status = decoded.get("status")
            usage_obj = decoded.get("usage") or {}
            details = usage_obj.get("input_tokens_details") or {}
            usage = {
                "input_tokens": int(usage_obj.get("input_tokens") or 0),
                "cached_input_tokens": int(details.get("cached_tokens") or 0),
                "output_tokens": int(usage_obj.get("output_tokens") or 0),
            }
            extraction = json.loads(output_text(decoded))
            if not isinstance(extraction, dict):
                raise RuntimeError("OPENAI_EXTRACTION_NOT_OBJECT")
        except Exception as exc:  # one request, no semantic retry
            error_class = safe_error(exc)
        latency = time.perf_counter() - started

        results.append(
            {
                "id": case["id"],
                "response_id": response_id,
                "response_status": status,
                "ai_latency_seconds": round(latency, 6),
                "input_tokens": usage["input_tokens"],
                "cached_input_tokens": usage["cached_input_tokens"],
                "output_tokens": usage["output_tokens"],
                "extraction": extraction,
                "error_class": error_class,
            }
        )

    Path(args.output).write_text(
        json.dumps(
            {
                "provider": "openai",
                "model_id": args.model,
                "request_count": len(results),
                "semantic_retry": False,
                "cases": results,
            },
            ensure_ascii=False,
            indent=2,
        )
        + "\n",
        encoding="utf-8",
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
