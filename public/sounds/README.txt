notification.wav is the in-app notification chime, played by
resources/js/composables/useNotificationSound.ts when a broadcast notification
arrives while the tab is open. Web push notifications (public/sw.js) use the
operating system sound instead and are not affected by this file.

It is a generated two-tone bell (A5 -> E6, ~0.62s, 44.1 kHz 16-bit mono): a
fundamental plus two decaying harmonics per note, fast attack, exponential
decay, faded to silence at the tail.

To swap in a different sound, drop the replacement here and point SOUND_URL in
useNotificationSound.ts at it. Keep it short (under ~1s) and quiet — the
composable plays it at volume 0.4. NotificationSoundTest pins the two together,
so the URL and the file on disk cannot drift apart.
