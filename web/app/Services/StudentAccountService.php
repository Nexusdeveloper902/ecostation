<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Automatic student login provisioning.
 *
 * Creating or importing a student mints the 1:1 self-service account in
 * the same transaction: the account can never exist without its student
 * and a half-applied import can never leave orphaned logins.
 *
 * Convention (the demo seed keeps it):
 *   email    = {ascii-lower-first-name}@<settings student email domain>
 *   password = aleatoria por estudiante (override explícito solo en
 *              pruebas/despliegues controlados)
 *   flag     = must_change_password=true (forced rotation on first login)
 *
 * Collisions get a numeric suffix (maria@…, maria2@…): the first-try
 * address always follows the convention; only true collisions
 * disambiguate. Unique-violation retries back every write, so a lost
 * race degrades to the idempotent answer instead of a 500.
 */
class StudentAccountService
{
    /**
     * Provision (or return the existing) account for a student.
     *
     * Idempotent: an existing account is never re-issued a temporary
     * password. The generated password is returned ONCE to the caller
     * that created the account (the roster flashes it).
     *
     * @return array{user: User, already: bool, password: ?string}
     */
    public function provisionFor(Student $student): array
    {
        $existing = $student->account()->first();

        if ($existing !== null) {
            return ['user' => $existing, 'already' => true, 'password' => null];
        }

        $password = $this->initialPassword();

        try {
            $user = User::forceCreate([
                'name' => $student->name,
                'email' => $this->emailForName($student->name),
                'password' => $password,
                'role' => UserRole::Student->value,
                'student_id' => $student->id,
                'must_change_password' => true,
            ]);
        } catch (QueryException $e) {
            // Lost race: whoever won owns the answer — re-read, never 500.
            if ($e->getCode() !== '23000') {
                throw $e;
            }

            $winner = $student->account()->first()
                ?? User::where('email', $this->emailForName($student->name))->first();

            if ($winner === null) {
                throw $e;
            }

            return ['user' => $winner, 'already' => true, 'password' => null];
        }

        return ['user' => $user->fresh() ?? $user, 'already' => false, 'password' => $password];
    }

    /**
     * Provision a whole import batch with ONE pre-read: suffixes allocate
     * against an in-memory set, so big imports stay linear. Each new
     * student gets its own generated password, returned for the one-time
     * import flash.
     *
     * @param  Collection<int, Student>  $students
     * @return array<int, array{email: string, password: string}> student_id => credentials
     */
    public function provisionMany(Collection $students): array
    {
        $domain = (string) settings()->studentEmailDomain();

        $taken = User::query()
            ->where('email', 'like', '%@'.$domain)
            ->pluck('email')
            ->flip()
            ->all();

        $credentials = [];

        foreach ($students as $student) {
            $existing = $student->account()->first();

            if ($existing !== null) {
                $credentials[$student->id] = ['email' => $existing->email, 'password' => ''];
                $taken[$existing->email] = true;

                continue;
            }

            $email = $this->emailForName($student->name, $taken);
            $taken[$email] = true;
            $password = $this->initialPassword();

            try {
                User::forceCreate([
                    'name' => $student->name,
                    'email' => $email,
                    'password' => $password,
                    'role' => UserRole::Student->value,
                    'student_id' => $student->id,
                    'must_change_password' => true,
                ]);
            } catch (QueryException $e) {
                // External race (out-of-batch writer won): fall back to
                // the live allocator, which re-reads the table.
                if ($e->getCode() !== '23000') {
                    throw $e;
                }

                $user = $this->provisionFor($student->refresh())['user'];
                $email = $user->email;
                $password = '';
                $taken[$email] = true;
            }

            $credentials[$student->id] = ['email' => $email, 'password' => $password];
        }

        return $credentials;
    }

    /**
     * The explicit override when configured; otherwise a fresh random
     * password, never the old shared demo secret.
     */
    private function initialPassword(): string
    {
        return settings()->studentInitialPassword() ?? Str::password(12);
    }

    /**
     * The login email for a display name, disambiguated until unique.
     *
     * @param  array<string, true>  $reserved  in-memory take-list (bulk path)
     */
    public function emailForName(string $name, array $reserved = []): string
    {
        $slug = (string) str(trim($name))->before(' ')->lower()->ascii();
        $slug = preg_replace('/[^a-z0-9._-]/', '', $slug) ?? '';

        if ($slug === '') {
            $slug = 'student';
        }

        $domain = (string) settings()->studentEmailDomain();
        $candidate = "{$slug}@{$domain}";

        $suffix = 2;
        while (isset($reserved[$candidate]) || User::where('email', $candidate)->exists()) {
            $candidate = "{$slug}{$suffix}@{$domain}";
            $suffix++;

            if ($suffix > 1000) {
                do {
                    $candidate = $slug.Str::random(6).'@'.$domain;
                } while (isset($reserved[$candidate]) || User::where('email', $candidate)->exists());

                break;
            }
        }

        return $candidate;
    }
}
