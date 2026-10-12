<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Exceptions\FiscalException;
use App\Http\JsonResponse;
use App\Http\Request;
use App\Services\Fiscal\AccountPasswordService;

final class AccountController
{
    private AccountPasswordService $passwordService;

    public function __construct(AccountPasswordService $passwordService)
    {
        $this->passwordService = $passwordService;
    }

    public function changePassword(Request $request): JsonResponse
    {
        $password = $request->input('password');
        if (!is_string($password)) {
            throw new FiscalException('Informe a nova senha.', 'PASSWORD_REQUIRED', [], 422);
        }

        $this->passwordService->changeForAuthenticatedUser(
            (string) $request->header('authorization', ''),
            $password
        );

        return new JsonResponse(200, [
            'success' => true,
            'message' => 'Senha atualizada com segurança.',
        ]);
    }
}
