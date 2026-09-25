<?php

namespace Goldnead\Teams\Support;

use Goldnead\Teams\Models\Team;

/**
 * Join codes: read aloud in a rehearsal, typed off a sheet of paper.
 *
 * The alphabet leaves out what is confused that way (0/O, 1/I/L), and the
 * input is normalised the same way before lookup: case, spaces and dashes
 * do not matter.
 */
class JoinCodes
{
    public function generate(): string
    {
        $alphabet = (string) config('teams.join_codes.alphabet', 'ABCDEFGHJKMNPQRSTUVWXYZ23456789');
        $length = max(6, (int) config('teams.join_codes.length', 10));
        $last = strlen($alphabet) - 1;

        do {
            $code = '';

            for ($i = 0; $i < $length; $i++) {
                $code .= $alphabet[random_int(0, $last)];
            }
        } while (Team::query()->where('join_code', $code)->exists());

        return $code;
    }

    public function normalise(string $code): string
    {
        return strtoupper((string) preg_replace('/[\s\-]+/', '', $code));
    }

    public function find(string $code): ?Team
    {
        $code = $this->normalise($code);

        if ($code === '') {
            return null;
        }

        return Team::query()->where('join_code', $code)->first();
    }
}
