<?php

declare(strict_types=1);

namespace Type\Queue;

use Type\Runtime\ExecutionScope;

final class JobContext
{
    private ExecutionScope $scope;
    private Reservation $reservation;
    public function __construct(ExecutionScope $scope, Reservation $reservation)
    {
        $this->scope = $scope;
        $this->reservation = $reservation;
        $reservation->bindScope($scope);
    }
    public function scope(): ExecutionScope
    {
        return $this->scope;
    }
    public function message(): Message
    {
        return $this->reservation->message();
    }
    public function reservation(): Reservation
    {
        return $this->reservation;
    }
    public function assertActive(): void
    {
        $this->scope->assertActive();
        $this->reservation->assertOwned();
    }
}
