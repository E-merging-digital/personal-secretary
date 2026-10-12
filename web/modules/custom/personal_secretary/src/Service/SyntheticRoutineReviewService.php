<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Service;

use Drupal\personal_secretary\Value\SyntheticRoutineProposal;
use InvalidArgumentException;

/**
 * Pure test-only, synthetic conversation review; never saves or approves a rule.
 *
 * All 'turns' are already-typed commands provided by an inventoried test double.
 * No natural-language interpreter, real identities or domain services are invoked.
 */
final class SyntheticRoutineReviewService {

  public function __construct(private readonly SyntheticRoutinePreviewService $preview = new SyntheticRoutinePreviewService()) {}

  /** @param array<string, array<string, mixed>> $days */
  public function start(SyntheticRoutineProposal $proposal, string $syntheticHousehold, array $days): array {
    $rows = $this->preview->preview($proposal, $syntheticHousehold, $days);
    $state = [
      'proposal' => $proposal,
      'household' => $syntheticHousehold,
      'days' => $days,
      'overrides' => [],
      'revision' => 1,
      'preview' => $rows,
      'before' => $rows,
      'status' => $this->hasUnknown($rows) ? 'NEEDS_CLARIFICATION' : 'READY_FOR_REVIEW',
      'clarification' => $this->hasUnknown($rows) ? 'Contexte ou horaire fictif à confirmer.' : NULL,
      'provenance' => 'SYNTHETIC_ONLY',
      'saved' => FALSE,
      'last_turn' => 'Tour 1 : déclaration fictive inventée.',
    ];
    $state['fingerprint'] = $this->fingerprint($state, $rows);
    return $state;
  }

  /**
   * Apply only one explicitly typed fictional correction or ambiguity.
   *
   * @param array<string, mixed> $state
   * @param array<string, mixed> $command
   *   Either INTENTION, DAY_SCHOOL, or AMBIGUOUS; never arbitrary text.
   */
  public function correct(array $state, array $command): array {
    $this->assertState($state);
    if (in_array($state['status'], ['PREVIEW_ACCEPTED_ONLY', 'DECLINED', 'STALE_PROPOSAL'], TRUE)) {
      return $this->status($state, 'STALE_PROPOSAL', 'Cette revue a déjà été terminée.');
    }
    if (($command['expected_revision'] ?? NULL) !== $state['revision']) {
      return $this->status($state, 'STALE_PROPOSAL', 'Correction basée sur une révision ancienne.');
    }
    $type = $command['type'] ?? NULL;
    if ($type === 'AMBIGUOUS') {
      $this->requireKeys($command, ['type', 'expected_revision']);
      return $this->status($state, 'NEEDS_CLARIFICATION', 'Déclaration fictive ambiguë : correction structurée requise.');
    }
    $proposal = $state['proposal'];
    $overrides = $state['overrides'];
    if ($type === 'INTENTION') {
      $this->requireKeys($command, ['type', 'expected_revision', 'field', 'enabled']);
      if (!in_array($command['field'], ['suggestPreparation', 'suggestMorningStops', 'suggestAfternoonCollection', 'requireSchoolDay'], TRUE)
        || !is_bool($command['enabled'])) {
        return $this->status($state, 'NEEDS_CLARIFICATION', 'Intention typée non reconnue.');
      }
      $field = $command['field'];
      if ($proposal->$field === $command['enabled']) {
        return $this->status($state, 'NEEDS_CLARIFICATION', 'Correction sans changement de la règle.');
      }
      // The existing typed proposal stays authoritative; no new rule language.
      $proposal = new SyntheticRoutineProposal(
        key: $proposal->key,
        version: $proposal->version + 1,
        fictionalSentence: $proposal->fictionalSentence,
        householdKey: $proposal->householdKey,
        startDate: $proposal->startDate,
        requireSchoolDay: $field === 'requireSchoolDay' ? $command['enabled'] : $proposal->requireSchoolDay,
        requirePhysicalPresence: $proposal->requirePhysicalPresence,
        suggestPreparation: $field === 'suggestPreparation' ? $command['enabled'] : $proposal->suggestPreparation,
        suggestMorningStops: $field === 'suggestMorningStops' ? $command['enabled'] : $proposal->suggestMorningStops,
        suggestAfternoonCollection: $field === 'suggestAfternoonCollection' ? $command['enabled'] : $proposal->suggestAfternoonCollection,
        timezone: $proposal->timezone,
      );
    }
    elseif ($type === 'DAY_SCHOOL') {
      $this->requireKeys($command, ['type', 'expected_revision', 'date', 'school_day']);
      if (!is_string($command['date']) || !array_key_exists($command['date'], $state['days'])
        || !is_bool($command['school_day'])) {
        return $this->status($state, 'NEEDS_CLARIFICATION', 'Date ou état scolaire non confirmé.');
      }
      $date = $command['date'];
      $prior = $overrides[$date]['school_day'] ?? $state['days'][$date]['school_day'];
      if ($prior === $command['school_day']) {
        return $this->status($state, 'NEEDS_CLARIFICATION', 'Aucune correction ponctuelle nécessaire.');
      }
      $overrides[$date]['school_day'] = $command['school_day'];
    }
    else {
      return $this->status($state, 'NEEDS_CLARIFICATION', 'Instruction non supportée : aucun changement.');
    }
    $after = $this->preview->preview($proposal, $state['household'], $state['days'], $overrides);
    $updated = $state;
    $updated['proposal'] = $proposal;
    $updated['overrides'] = $overrides;
    $updated['before'] = $state['preview'];
    $updated['preview'] = $after;
    $updated['revision'] = $state['revision'] + 1;
    $updated['last_turn'] = $type === 'INTENTION'
      ? 'Tour 2 : correction intentionnelle fictive (' . $field . '=' . ($command['enabled'] ? 'oui' : 'non') . ').'
      : 'Tour 2 : correction fictive pour le seul ' . $command['date'] . '.';
    $updated['status'] = $this->hasUnknown($after) ? 'NEEDS_CLARIFICATION' : 'READY_FOR_REVIEW';
    $updated['clarification'] = $this->hasUnknown($after) ? 'Contexte ou horaire fictif à confirmer.' : NULL;
    $updated['fingerprint'] = $this->fingerprint($updated, $after);
    return $updated;
  }

  /**
   * Explicitly approve ONLY current preview or decline, never a saved routine.
   *
   * @param array<string, mixed> $decision
   *   REVIEW, expected_revision, expected_fingerprint and explicit action.
   * @param array<string, array<string, mixed>>|null $currentDays
   *   Current fictional context for fresh re-evaluation; none means unchanged.
   */
  public function review(array $state, array $decision, ?array $currentDays = NULL): array {
    $this->assertState($state);
    if ($state['status'] === 'PREVIEW_ACCEPTED_ONLY' || $state['status'] === 'DECLINED' || $state['status'] === 'STALE_PROPOSAL') {
      return $this->status($state, 'STALE_PROPOSAL', 'Décision déjà consommée.');
    }
    if (($decision['type'] ?? NULL) !== 'REVIEW') {
      return $this->status($state, 'NEEDS_CLARIFICATION', 'Décision explicite attendue.');
    }
    $this->requireKeys($decision, ['type', 'expected_revision', 'expected_fingerprint', 'action']);
    if ($decision['expected_revision'] !== $state['revision']
      || !is_string($decision['expected_fingerprint'])
      || !hash_equals($state['fingerprint'], $decision['expected_fingerprint'])) {
      return $this->status($state, 'STALE_PROPOSAL', 'Version ou empreinte de revue périmée.');
    }
    // Always re-evaluate the exact context, including per-date overrides.
    $fresh = $this->preview->preview($state['proposal'], $state['household'], $currentDays ?? $state['days'], $state['overrides']);
    if (!hash_equals($state['fingerprint'], $this->fingerprint($state, $fresh, $currentDays ?? $state['days']))) {
      return $this->status($state, 'STALE_PROPOSAL', 'Contexte fictif modifié depuis la revue.');
    }
    if ($decision['action'] === 'DECLINE') {
      return $this->status($state, 'DECLINED', 'Aucun aperçu accepté, aucune règle sauvegardée.');
    }
    if ($decision['action'] !== 'ACCEPT_PREVIEW') {
      return $this->status($state, 'NEEDS_CLARIFICATION', 'Acceptation ou refus explicite requis.');
    }
    if ($state['status'] !== 'READY_FOR_REVIEW' || $this->hasUnknown($fresh)) {
      return $this->status($state, 'NEEDS_CLARIFICATION', 'Incertitudes à résoudre avant une revue explicite.');
    }
    return $this->status($state, 'PREVIEW_ACCEPTED_ONLY', 'Aperçu actuel uniquement accepté. NOT_SAVED.');
  }

  /** Render the exactly fourteen BEFORE/AFTER days of the fictional review. */
  public function markdown(array $state): string {
    $this->assertState($state);
    $p = $state['proposal'];
    $lines = [
      '# Dialogue et aperçu fictifs — PREVIEW_ONLY / NOT_SAVED', '',
      'Provenance : SYNTHETIC_ONLY ; interprétation : fixture déterministe, sans IA réelle.',
      'Tour 1 : ' . $p->fictionalSentence,
      $state['last_turn'],
      'Tour 3 : décision de revue = ' . $state['status'] . ' (jamais une règle sauvegardée).',
      'Household : ' . $p->householdKey . ' ; date : ' . $p->startDate . ' ; timezone : ' . $p->timezone . '.',
      'Révision de revue : ' . $state['revision'] . ' ; version de proposition : ' . $p->version . '.',
      'Conditions : présence physique=' . ($p->requirePhysicalPresence ? 'oui' : 'non') . ', jour scolaire=' . ($p->requireSchoolDay ? 'oui' : 'non') . '.',
      'Intentions : préparation=' . ($p->suggestPreparation ? 'oui' : 'non') . ', étapes matin=' . ($p->suggestMorningStops ? 'oui' : 'non') . ', collecte après-midi=' . ($p->suggestAfternoonCollection ? 'oui' : 'non') . '.',
      'Statut : ' . $state['status'] . ' ; sauvegarde : NON ; empreinte de revue : ' . $state['fingerprint'] . '.',
      'Clarification : ' . ($state['clarification'] ?? 'aucune') . '.', '',
      '| Date | Avant (statut, actions virtuelles) | Après (statut, actions virtuelles) | Changement | Explications (après) |',
      '| --- | --- | --- | --- | --- |',
    ];
    foreach ($state['preview'] as $i => $after) {
      $before = $state['before'][$i];
      $summarize = static function (array $day): string {
        $kinds = array_map(static fn(array $a): string => $a['kind'] . ':' . $a['detail'], $day['actions']);
        return $day['status'] . ' / ' . (implode(', ', $kinds) ?: 'aucune') . ' / ' . $day['exchange'];
      };
      $lines[] = '| ' . $after['date'] . ' | ' . $summarize($before) . ' | ' . $summarize($after)
        . ' | ' . ($before === $after ? 'inchangé' : 'modifié')
        . ' | ' . (implode(' ', $after['why']) ?: 'aucune') . ' |';
    }
    return implode("\n", $lines) . "\n";
  }

  private function assertState(array $state): void {
    if (!($state['proposal'] ?? NULL) instanceof SyntheticRoutineProposal
      || ($state['provenance'] ?? NULL) !== 'SYNTHETIC_ONLY'
      || ($state['saved'] ?? TRUE) !== FALSE
      || !is_array($state['preview'] ?? NULL)
      || count($state['preview']) !== 14
      || !is_string($state['fingerprint'] ?? NULL)) {
      throw new InvalidArgumentException('Only existing transient fictional review state is supported.');
    }
  }

  private function requireKeys(array $command, array $allowed): void {
    $keys = array_keys($command);
    sort($keys);
    sort($allowed);
    if ($keys !== $allowed) {
      throw new InvalidArgumentException('Unrecognized command contract fields.');
    }
  }

  private function hasUnknown(array $preview): bool {
    foreach ($preview as $day) {
      if ($day['status'] === SyntheticRoutinePreviewService::NEEDS_CONFIRMATION) {
        return TRUE;
      }
    }
    return FALSE;
  }

  private function fingerprint(array $state, array $preview, ?array $days = NULL): string {
    $p = $state['proposal'];
    return hash('sha256', json_encode([
      'key' => $p->key, 'proposal_version' => $p->version,
      'require_school' => $p->requireSchoolDay, 'require_presence' => $p->requirePhysicalPresence,
      'preparation' => $p->suggestPreparation, 'morning' => $p->suggestMorningStops, 'afternoon' => $p->suggestAfternoonCollection,
      'household' => $state['household'], 'revision' => $state['revision'],
      'context' => $days ?? $state['days'], 'overrides' => $state['overrides'], 'preview' => $preview,
    ], JSON_THROW_ON_ERROR));
  }

  private function status(array $state, string $status, string $reason): array {
    $state['status'] = $status;
    $state['clarification'] = $reason;
    return $state;
  }

}
