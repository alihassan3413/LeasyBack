<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The notification chime is a plain file under public/ referenced by a string
 * literal in useNotificationSound.ts — nothing at build time would catch the
 * two drifting apart, so it is pinned here: a missing or renamed asset silently
 * degrades to no sound in the browser (the composable swallows the 404).
 */
class NotificationSoundTest extends TestCase
{
    private const COMPOSABLE = 'resources/js/composables/useNotificationSound.ts';

    public function test_the_composable_points_at_a_sound_file_that_exists(): void
    {
        $url = $this->configuredSoundUrl();

        $this->assertFileExists(public_path(ltrim($url, '/')));
    }

    public function test_the_chime_is_a_playable_non_empty_audio_file(): void
    {
        $path = public_path(ltrim($this->configuredSoundUrl(), '/'));
        $size = (int) filesize($path);

        $this->assertGreaterThan(1024, $size, 'The chime looks empty or truncated.');
        $this->assertLessThan(512 * 1024, $size, 'The chime is too large to ship as a UI sound.');

        $header = (string) file_get_contents($path, length: 12);

        $this->assertSame('RIFF', substr($header, 0, 4));
        $this->assertSame('WAVE', substr($header, 8, 4));
    }

    /**
     * The SOUND_URL literal declared by the composable.
     */
    private function configuredSoundUrl(): string
    {
        $source = (string) file_get_contents(base_path(self::COMPOSABLE));

        $this->assertMatchesRegularExpression(
            '/const SOUND_URL = \'(?<url>[^\']+)\';/',
            $source,
            'useNotificationSound.ts no longer declares a SOUND_URL constant.'
        );

        preg_match('/const SOUND_URL = \'(?<url>[^\']+)\';/', $source, $matches);

        return $matches['url'];
    }
}
