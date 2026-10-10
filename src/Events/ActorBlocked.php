<?php

namespace QuadCompanies\QuadPayment\Events;

use QuadCompanies\QuadPayment\Models\Block;

class ActorBlocked
{
    public function __construct(public readonly Block $block) {}
}
