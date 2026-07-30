<?php

declare(strict_types=1);

namespace Drupal\flowspeech\Audio;

/**
 * Wraps raw PCM data in a standard WAV container.
 */
final class WavEncoder {

  /**
   * Encodes PCM bytes as a WAV file.
   */
  public static function encode(
    string $pcm,
    int $sample_rate = 24000,
    int $channels = 1,
    int $bits_per_sample = 16,
  ): string {
    if ($sample_rate <= 0 || $channels <= 0) {
      throw new \InvalidArgumentException('Sample rate and channel count must be positive.');
    }
    if (!in_array($bits_per_sample, [8, 16, 24, 32], TRUE)) {
      throw new \InvalidArgumentException('Unsupported PCM bit depth.');
    }

    $data_length = strlen($pcm);
    $block_align = $channels * intdiv($bits_per_sample, 8);
    $byte_rate = $sample_rate * $block_align;

    return 'RIFF'
      . pack('V', 36 + $data_length)
      . 'WAVE'
      . 'fmt '
      . pack(
        'VvvVVvv',
        16,
        1,
        $channels,
        $sample_rate,
        $byte_rate,
        $block_align,
        $bits_per_sample,
      )
      . 'data'
      . pack('V', $data_length)
      . $pcm;
  }

}
