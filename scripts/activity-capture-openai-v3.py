"""Benchmark-only OpenAI Responses API transport for BENCHMARK_V3.

Semantic prompts and schema are supplied by the PHP parity exporter generated
from the current ActivityCaptureInterpreter and ActivityCaptureExtraction.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import os
import time
import urllib.error
import urllib.request
from pathlib import Path
from typing import Any

API_URL = "https://api.openai.com/v1/responses"
FROZEN_FIXTURE_SHA256 = "bb31e0ba98db688adcdfd530275bf3aff47013c79d4c6bb2c4896b1477cc8cbd"


def file_sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def output_text(response: dict[str, Any]) -> str:
    for item in response.get("output", []):
        if item.get("type") != "message":
            continue
        for part in item.get("content", []):
            if part.get("type") == "output_text" and isinstance(part.get("text"), str):
                return part["text"]
            if part.get("type") == "refusal":
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
    parser.add_argument("--semantic-payload", required=True)
    parser.add_argument("--output", required=True)
    args = parser.parse_args()

    api_key = os.environ.get("OPENAI_API_KEY", "")
    if not api_key:
        raise SystemExit("OPENAI_API_KEY_PRESENT=NO")

    fixture_path = Path(args.fixture)
    semantic_path = Path(args.semantic_payload)
    if file_sha256(fixture_path) != FROZEN_FIXTURE_SHA256:
        raise SystemExit("FROZEN_V3_FIXTURE_SHA_MISMATCH")

    fixture = json.loads(fixture_path.read_text(encoding="utf-8"))
    semantic = json.loads(semantic_path.read_text(encoding="utf-8"))
    cases = fixture.get("cases")
    semantic_cases = semantic.get("cases")
    if not isinstance(cases, list) or len(cases) != 20:
        raise SystemExit("FROZEN_V3_CASE_COUNT_MISMATCH")
    if semantic.get("fixture_sha256") != FROZEN_FIXTURE_SHA256:
        raise SystemExit("SEMANTIC_PAYLOAD_FIXTURE_SHA_MISMATCH")
    if not isinstance(semantic_cases, list) or len(semantic_cases) != 20:
        raise SystemExit("SEMANTIC_PAYLOAD_CASE_COUNT_MISMATCH")

    system_prompt = semantic["system_prompt"]
    schema = semantic["schema"]
    semantic_by_id = {row["id"]: row for row in semantic_cases}
    if [row["id"] for row in semantic_cases] != [row["id"] for row in cases]:
        raise SystemExit("SEMANTIC_PAYLOAD_CASE_ORDER_MISMATCH")

    results: list[dict[str, Any]] = []
    for case in cases:
        semantic_case = semantic_by_id[case["id"]]
        user_prompt = semantic_case["user_prompt"]
        payload = {
            "model": args.model,
            "reasoning": {"effort": "none"},
            "tools": [],
            "background": False,
            "store": False,
            "input": [
                {
                    "role": "system",
                    "content": [{"type": "input_text", "text": system_prompt}],
                },
                {
                    "role": "user",
                    "content": [{"type": "input_text", "text": user_prompt}],
                },
            ],
            "text": {
                "format": {
                    "type": "json_schema",
                    "name": "activity_capture_extraction",
                    "strict": True,
                    "schema": schema,
                }
            },
        }

        started = time.perf_counter()
        extraction = None
        response_id = None
        response_status = None
        error_class = None
        usage = {
            "input_tokens": 0,
            "cached_input_tokens": 0,
            "output_tokens": 0,
        }
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
            response_status = decoded.get("status")
            usage_obj = decoded.get("usage") or {}
            input_details = usage_obj.get("input_tokens_details") or {}
            usage = {
                "input_tokens": int(usage_obj.get("input_tokens") or 0),
                "cached_input_tokens": int(input_details.get("cached_tokens") or 0),
                "output_tokens": int(usage_obj.get("output_tokens") or 0),
            }
            extraction = json.loads(output_text(decoded))
            if not isinstance(extraction, dict):
                raise RuntimeError("OPENAI_EXTRACTION_NOT_OBJECT")
        except Exception as exc:  # exactly one semantic request; never retry
            error_class = safe_error(exc)

        results.append(
            {
                "id": case["id"],
                "response_id": response_id,
                "response_status": response_status,
                "provider_latency_seconds": round(time.perf_counter() - started, 6),
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
                "fixture_sha256": FROZEN_FIXTURE_SHA256,
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
