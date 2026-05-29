<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/blog')]
final class BlogController
{
    #[Route('/list', name: 'blog_list', methods: [Request::METHOD_GET])]
    public function list(#[\SensitiveParameter] string $token): void
    {
    }
}
