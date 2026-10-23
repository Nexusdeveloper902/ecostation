<?php

namespace App\Services\Recycling;

/**
 * Thrown (and caught) inside CaptureService when a concurrent
 * association claimed a pending capture between the outer usability
 * pre-check and the transaction's lockForUpdate re-check. The loser
 * surfaces a friendly already_associated answer, never a race 500.
 */
class CaptureAlreadyAssociated extends \RuntimeException {}
