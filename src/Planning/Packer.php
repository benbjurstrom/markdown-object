<?php

namespace BenBjurstrom\MarkdownObject\Planning;

final class Packer
{
    /**
     * @param  list<Unit>  $units
     * @return list<array{start:int,end:int}>
     */
    public function pack(array $units, Budget $budget, bool $allowFinalStretchToHardCap = true): array
    {
        $ranges = [];
        $n = count($units);
        if ($n === 0) {
            return $ranges;
        }

        $start = 0;
        $sum = 0;

        for ($i = 0; $i < $n; $i++) {
            $u = $units[$i];
            $next = $sum + $u->tokens;
            $isLast = ($i === $n - 1);

            if ($next <= $budget->target) {
                $sum = $next;
                if ($isLast) {
                    $ranges[] = ['start' => $start, 'end' => $i];
                    $start = $i + 1;
                }

                continue;
            }

            if ($isLast && $allowFinalStretchToHardCap && $next <= $budget->hardCap) {
                $ranges[] = ['start' => $start, 'end' => $i];
                $start = $i + 1;
                $sum = 0;

                continue;
            }

            // flush [start .. i-1]
            if ($i - 1 >= $start) {
                $ranges[] = ['start' => $start, 'end' => $i - 1];
            }
            $start = $i;
            $sum = $u->tokens;

            if ($isLast || $sum >= $budget->earlyThreshold) {
                $ranges[] = ['start' => $start, 'end' => $i];
                $start = $i + 1;
                $sum = 0;
            }
        }

        if ($start < $n) {
            $ranges[] = ['start' => $start, 'end' => $n - 1];
        }

        return $ranges;
    }
}
