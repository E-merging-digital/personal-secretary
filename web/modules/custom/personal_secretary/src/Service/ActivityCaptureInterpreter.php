<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Service;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\personal_secretary\Value\ActivityCaptureExtraction;
use Drupal\personal_secretary\Value\ActivityCaptureInput;
use InvalidArgumentException;
use RuntimeException;

/**
 * Extracts narrow LOCAL_ONLY linguistic candidates through Drupal AI.
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

  public function interpret(ActivityCaptureInput $input): ActivityCaptureExtraction {
    $chatInput = new ChatInput([
      new ChatMessage('user', $this->buildUserPrompt($input)),
    ]);
    $chatInput->setSystemPrompt($this->systemPrompt());
    $chatInput->setChatStructuredJsonSchema([
      'name' => 'activity_capture_extraction',
      'strict' => FALSE,
      'schema' => ActivityCaptureExtraction::structuredJsonSchema(),
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

    return ActivityCaptureExtraction::fromProviderArray($decoded);
  }

  private function buildUserPrompt(ActivityCaptureInput $input): string {
    return implode("\n", [
      'Contexte synthétique/local uniquement.',
      'Instant UTC figé: ' . $input->contextInstantIso8601(),
      'Fuseau applicatif connu: ' . $input->sourceTimezone,
      'Demande: «' . $input->text . '»',
      'Retourne uniquement les candidats linguistiques demandés par le schéma.',
    ]);
  }

  private function systemPrompt(): string {
    return <<<'PROMPT'
Tu extrais uniquement des candidats linguistiques à partir d'une courte demande française.
Tu ne produis jamais une décision métier, une autorisation, un identifiant interne, un UUID, un Household, une RRULE ou un fuseau horaire.
label_text contient un libellé d'activité court déduit du texte, ou null si aucun libellé fiable n'est extractible.
location_text contient uniquement un lieu explicitement exprimé, sinon null.
concerned_person_mentions contient uniquement les Personnes explicitement exprimées comme participantes, sujets, bénéficiaires ou directement concernées par l’activité.
Une Personne exprimée uniquement comme responsable ne doit pas être copiée dans concerned_person_mentions.
Si le texte donne explicitement les deux rôles à la même Personne, elle peut apparaître dans concerned_person_mentions et responsibility_candidate.
unclassified_person_mentions contient uniquement les mentions textuelles explicites de Personnes dont le rôle ne peut pas être classé de façon fiable comme concerné ou responsable; ne force jamais une ambiguïté dans un rôle connu.
concerned_person_alternative vaut true uniquement si les mentions concernées sont présentées comme des alternatives ou un choix, par exemple avec « ou ».
responsibility_candidate vaut "SELF" seulement si la responsabilité à la première personne est explicite; sinon le référent textuel explicite assigné à la responsabilité; sinon null.
date_expression conserve l'expression de date ou de jour telle qu'exprimée, sans la transformer en vérité métier.
date_day, date_month et date_year extraient uniquement les composantes numériques d'une date calendrier explicitement exprimée; laisse-les à null pour une date relative ou un simple jour de semaine.
relative_day_offset exprime seulement un sens relatif explicite et simple, par exemple aujourd'hui=0, demain=1, après-demain=2; sinon null.
start_time_expression et end_time_expression contiennent uniquement les expressions horaires explicitement présentes; n'invente jamais une durée ou une heure de fin.
recurrence_expression contient uniquement le fragment exprimant une répétition; null signifie qu'aucune répétition n'est exprimée.
explicit_all_day_signal vaut true uniquement si le texte exprime explicitement que l’activité couvre toute la journée, par exemple « toute la journée », « pour toute la journée », « journée entière de formation » ou une formulation sémantiquement équivalente.
La présence du mot « journée » seule dans « journée administrative », « journée pédagogique », « journée portes ouvertes », « journée de formation » ou « journée au bureau » ne suffit pas à établir ALL_DAY.
explicit_timed_signal vaut true si le texte exprime explicitement une heure ou un caractère horaire.
N'émets aucun drapeau global ambiguous/unsupported: la clarification, l'identité, la récurrence supportée et les valeurs finales sont résolues par l'application.
PROMPT;
  }

}
