<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Service;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\personal_secretary\Value\ActivityCaptureInput;
use Drupal\personal_secretary\Value\ActivityCaptureProposal;
use InvalidArgumentException;
use RuntimeException;

/**
 * Interprets LOCAL_ONLY capture text through the Drupal AI abstraction.
 */
final class ActivityCaptureInterpreter implements ActivityCaptureInterpreterInterface {

  public const PRIMARY_AI_ATTEMPTS = 1;
  public const MAX_TOTAL_ATTEMPTS = 2;

  public function __construct(
    private readonly AiProviderPluginManager $providerManager,
    private readonly string $providerId,
    private readonly string $modelId,
  ) {
    if (trim($providerId) === '' || trim($modelId) === '') {
      throw new InvalidArgumentException('Activity capture provider and model must be supplied by the runtime.');
    }
  }

  public function interpret(ActivityCaptureInput $input): ActivityCaptureProposal {
    $chatInput = new ChatInput([
      new ChatMessage('user', $this->buildUserPrompt($input)),
    ]);
    $chatInput->setSystemPrompt($this->systemPrompt());
    $chatInput->setChatStructuredJsonSchema([
      'name' => 'activity_capture_proposal',
      'strict' => FALSE,
      'schema' => ActivityCaptureProposal::structuredJsonSchema(),
    ]);

    $provider = $this->providerManager->createInstance($this->providerId);
    $output = $provider->chat($chatInput, $this->modelId, ['personal-secretary-activity-capture']);
    $normalized = $output->getNormalized();
    if (!$normalized instanceof ChatMessage) {
      throw new RuntimeException('Activity capture requires a non-streamed normalized chat response.');
    }

    $decoded = json_decode($normalized->getText(), TRUE, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
      throw new RuntimeException('Activity capture structured response must decode to an object.');
    }

    return ActivityCaptureProposal::fromArray($decoded);
  }

  private function buildUserPrompt(ActivityCaptureInput $input): string {
    return implode("\n", [
      'Contexte synthétique/local uniquement.',
      'Instant UTC figé: ' . $input->contextInstantIso8601(),
      'Fuseau source: ' . $input->sourceTimezone,
      'Demande: «' . $input->text . '»',
      'Retourne uniquement la proposition structurée demandée par le schéma.',
    ]);
  }

  private function systemPrompt(): string {
    return <<<'PROMPT'
Tu extrais une proposition d'activité à partir d'une courte demande française.
La proposition n'est jamais une autorité métier et ne crée aucune entité.
N'invente jamais d'identifiant, UUID, Person ID, Household ID ou graphe d'autorisation.
ONE_OFF signifie une seule occurrence; WEEKLY uniquement une répétition explicitement hebdomadaire.
TIMED signifie qu'une heure est explicitement fournie; ALL_DAY uniquement une activité sans heure couvrant la journée.
Pour une date relative, conserve l'expression dans relative_date_expression et laisse absolute_date à null.
Pour une date calendrier explicitement écrite, place YYYY-MM-DD dans absolute_date et relative_date_expression à null.
Pour une répétition hebdomadaire, renseigne weekday avec MONDAY..SUNDAY. local_time est HH:MM quand une heure est explicite.
source_timezone doit recopier exactement le fuseau fourni.
concerned_person_candidates contient uniquement des candidats textuels explicitement mentionnés.
responsibility vaut SELF pour une responsabilité explicite à la première personne, TEXT pour un responsable textuel explicite, sinon NONE.
unsupported vaut true pour une récurrence ou intention hors ONE_OFF/WEEKLY. ambiguous vaut true quand la demande ne permet pas une proposition fiable sans clarification.
Ne résous jamais une expression relative en date absolue.
PROMPT;
  }

}
