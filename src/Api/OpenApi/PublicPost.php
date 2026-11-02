<?php

declare(strict_types=1);

namespace App\Api\OpenApi;

use OpenApi\Attributes as OA;

#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class PublicPost extends OA\Post
{
    use PublicOperation;
}
