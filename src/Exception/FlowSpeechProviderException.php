<?php

declare(strict_types=1);

namespace Drupal\flowspeech\Exception;

use Drupal\ai\Exception\AiBadRequestException;
use Drupal\flowspeech\Api\FlowSpeechException;

final class FlowSpeechProviderException extends AiBadRequestException {

  public function __toString(): string {
    return FlowSpeechException::formatException($this);
  }

}
