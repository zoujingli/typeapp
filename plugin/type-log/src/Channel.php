<?php

declare(strict_types=1);

namespace Type\Log;

final class Channel
{
    private Output $output;
    private int $minimum;

    public function __construct(Output $output, string $minimumLevel = 'debug')
    {
        $this->output = $output;
        $this->minimum = Level::weight($minimumLevel);
    }

    public function output(): Output
    {
        return $this->output;
    }
    public function accepts(int $weight): bool
    {
        return $weight >= $this->minimum;
    }
}
