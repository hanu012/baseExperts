<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\Firebase\FirebaseAuthClient;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Copies app users that have no Firebase account yet into Firebase, with
 * their current passwords (bcrypt hashes imported as-is, so nobody has to
 * choose a new one). Run once after switching Settings → Sign-in to
 * Firebase, if you want every account in Firebase straight away.
 *
 * OPTIONAL. Without it, each account moves to Firebase by itself at its
 * next sign-in (AuthProvider). Safe to re-run: only users without a
 * firebase_uid are sent. Admins are skipped — they sign in to the panel,
 * never through Firebase. A user Firebase refuses (e.g. its email already
 * has a Firebase account) is listed and left for its next sign-in.
 */
class SyncUsersToFirebase extends Command
{
    protected $signature = 'firebase:sync-users {--chunk=500 : Users per Firebase request (max 1000)}';

    protected $description = 'Copy app users without a Firebase account into Firebase, keeping their passwords.';

    public function handle(FirebaseAuthClient $firebase): int
    {
        if (! $firebase->isConfigured()) {
            $this->error('Firebase is not set up (FCM_* in .env and the Firebase Web API key).');

            return self::FAILURE;
        }

        $imported = 0;
        $refused = [];

        User::query()
            ->whereNull('firebase_uid')
            ->where('role', '!=', UserRole::Admin->value)
            ->where('email', 'not like', '%@deleted.local')
            ->chunkById(min(1000, max(1, (int) $this->option('chunk'))), function ($users) use ($firebase, &$imported, &$refused) {
                $rows = $users->values()->map(fn (User $user) => [
                    'localId' => Str::random(28),
                    'email' => $user->email,
                    // Firebase's bcrypt reads the $2a$ prefix; PHP writes the
                    // identical algorithm as $2y$.
                    'passwordHash' => base64_encode(preg_replace('/^\$2y\$/', '\$2a\$', (string) $user->password)),
                    'displayName' => $user->name,
                ])->all();

                $failed = $firebase->importBcryptUsers($rows);

                foreach ($users->values() as $index => $user) {
                    if (in_array($index, $failed, true)) {
                        $refused[] = $user->email;

                        continue;
                    }

                    $user->forceFill(['firebase_uid' => $rows[$index]['localId']])->saveQuietly();
                    $imported++;
                }
            });

        $this->info("Copied {$imported} ".Str::plural('user', $imported).' to Firebase.');

        if ($refused !== []) {
            $this->warn('Refused by Firebase (they move over at their next sign-in instead): '.implode(', ', $refused));
        }

        return self::SUCCESS;
    }
}
