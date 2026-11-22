<?php

namespace App\Seller360\Simulation;

use RuntimeException;

/**
 * Hamulec nie może ustalić głębokości kolejki (brak kolejki, brak połączenia) albo czekał za długo.
 * Komenda zamienia go na czytelny komunikat dla operatora zamiast śladu stosu.
 */
class QueueBackpressureFailed extends RuntimeException {}
