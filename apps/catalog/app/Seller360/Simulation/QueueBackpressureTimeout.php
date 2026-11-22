<?php

namespace App\Seller360\Simulation;

/** Kolejka nie zeszła poniżej limitu w maksymalnym czasie czekania (konsument stoi albo jest za wolny). */
final class QueueBackpressureTimeout extends QueueBackpressureFailed {}
