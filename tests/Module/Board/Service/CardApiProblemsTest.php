<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Service;

use App\Api\Problem\ApiProblemException;
use App\Module\Board\Command\ChildDesignRefused;
use App\Module\Board\Service\CardApiProblems;
use PHPUnit\Framework\TestCase;

final class CardApiProblemsTest extends TestCase
{
    public function test_a_child_design_refusal_with_no_field_code_is_a_conflict(): void
    {
        $problem = new CardApiProblems()->from(new ChildDesignRefused('childDesign: The workflow refused the "own" choice (x).'));

        self::assertInstanceOf(ApiProblemException::class, $problem);
        self::assertSame(409, $problem->problem->status);
        self::assertSame('child_design_refused', $problem->problem->code);
    }

    public function test_a_child_design_refusal_with_a_field_code_is_a_validation_error_on_the_field(): void
    {
        $problem = new CardApiProblems()->from(new ChildDesignRefused('childDesign: Pass no status.', ChildDesignRefused::WITH_STATUS));

        self::assertInstanceOf(ApiProblemException::class, $problem);
        self::assertSame(422, $problem->problem->status);
        self::assertSame([['field' => 'childDesign', 'code' => 'child_design_with_status', 'message' => 'Pass no status.']], $problem->problem->errors);
    }
}
