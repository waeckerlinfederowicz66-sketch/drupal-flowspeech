<?php

declare(strict_types=1);

namespace Drupal\Tests\flowspeech\Unit;

use Drupal\flowspeech\Audio\WavEncoder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests PCM to WAV encoding.
 *
 */
#[CoversClass(WavEncoder::class)]
final class WavEncoderTest extends TestCase {

  /**
   * Verifies the WAV header and data length.
   *
   */
  public function testEncode(): void {
    $pcm = "\x01\x02\x03\x04";
    $wav = WavEncoder::encode($pcm, 24000, 1, 16);

    self::assertSame('RIFF', substr($wav, 0, 4));
    self::assertSame('WAVE', substr($wav, 8, 4));
    self::assertSame('fmt ', substr($wav, 12, 4));
    self::assertSame('data', substr($wav, 36, 4));
    self::assertSame(48, strlen($wav));
    self::assertSame($pcm, substr($wav, 44));
  }

  /**
   * Verifies invalid bit depths are rejected.
   *
   */
  public function testInvalidBitDepth(): void {
    $this->expectException(\InvalidArgumentException::class);
    WavEncoder::encode("\x00", 24000, 1, 12);
  }

}
