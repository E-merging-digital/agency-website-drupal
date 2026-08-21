<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Builds a diagnostic CRC-repaired candidate without changing the source asset.
 *
 * This test exists only while repairing issue #596 and must be removed before
 * the final PR is merged.
 *
 * @group agency_project_tests
 * @group governed_editorial_feature_image
 */
final class GovernedEditorialFeatureImageRepairCandidateTest extends TestCase {

  /**
   * Recomputing PNG chunk CRCs must be sufficient to recover the exact pixels.
   */
  public function testCrcOnlyRepairProducesGdDecodableCandidate(): void {
    $root = dirname(DRUPAL_ROOT);
    $profile = json_decode(
      (string) file_get_contents($root . '/scripts/runner/editorial-feature-image-profiles.json'),
      TRUE,
      16,
      JSON_THROW_ON_ERROR,
    );
    $source = $root . '/' . $profile['profiles']['401']['asset']['path'];
    $bytes = file_get_contents($source);
    self::assertIsString($bytes);
    self::assertStringStartsWith("\x89PNG\r\n\x1a\n", $bytes);

    $repaired = substr($bytes, 0, 8);
    $offset = 8;
    $length = strlen($bytes);
    $mismatches = [];
    $sawIend = FALSE;

    while ($offset < $length) {
      self::assertGreaterThanOrEqual($offset + 12, $length, sprintf('Truncated PNG chunk at byte %d.', $offset));
      $unpacked = unpack('Nlength', substr($bytes, $offset, 4));
      self::assertIsArray($unpacked);
      $dataLength = $unpacked['length'];
      $type = substr($bytes, $offset + 4, 4);
      $chunkEnd = $offset + 12 + $dataLength;
      self::assertLessThanOrEqual($length, $chunkEnd, sprintf('Truncated PNG chunk %s at byte %d.', $type, $offset));

      $data = substr($bytes, $offset + 8, $dataLength);
      $storedCrc = substr($bytes, $offset + 8 + $dataLength, 4);
      $computedCrc = pack('N', crc32($type . $data));
      if ($storedCrc !== $computedCrc) {
        $mismatches[] = sprintf(
          '%s@%d stored=%s computed=%s',
          $type,
          $offset,
          bin2hex($storedCrc),
          bin2hex($computedCrc),
        );
      }

      $repaired .= pack('N', $dataLength) . $type . $data . $computedCrc;
      $offset = $chunkEnd;
      if ($type === 'IEND') {
        $sawIend = TRUE;
        break;
      }
    }

    self::assertTrue($sawIend, 'PNG has no complete IEND chunk.');
    self::assertSame($length, $offset, 'PNG has trailing or truncated data after IEND.');
    self::assertNotEmpty($mismatches, 'The original asset unexpectedly has no CRC mismatch to repair.');

    $candidate = DRUPAL_ROOT . '/sites/simpletest/browser_output/issue-401-crc-repaired.png';
    self::assertNotFalse(file_put_contents($candidate, $repaired));

    $warning = NULL;
    set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
      $warning = $message;
      return TRUE;
    });
    try {
      $image = imagecreatefrompng($candidate);
    }
    finally {
      restore_error_handler();
    }

    self::assertInstanceOf(
      \GdImage::class,
      $image,
      sprintf(
        'CRC-only candidate is still not GD-decodable. mismatches=[%s]; warning=%s',
        implode(', ', $mismatches),
        $warning ?? 'none',
      ),
    );
    self::assertSame(1200, imagesx($image));
    self::assertSame(630, imagesy($image));
    imagedestroy($image);
  }

}
