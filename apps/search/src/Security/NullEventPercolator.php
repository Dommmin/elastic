<?php

namespace App\Security;

/** Domyślna implementacja do czasu Task 5: nic nie percoluje. */
final class NullEventPercolator implements EventPercolator
{
    public function percolate(array $refs): int
    {
        return 0;
    }
}
