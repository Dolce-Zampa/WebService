<?php
namespace PS\Webservice\Service\Auth;

use PS\Webservice\Domain\Models\User;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Stringable;

class UserService extends AuthServiceProvider {

    /**
     * Deletes a user.
     *
     * @param Request $request The HTTP request object.
     * @param Response $response The HTTP response object.
     * @param array $args The route parameters.
     * @return Response The updated HTTP response object.
     */
    public function delete(Stringable $uuid)
    {
        $user = User::where('uuid', $uuid)->first();
        if ($user) {
            $user->delete();
            return response(['message' => 'User deleted successfully'], 200);
        }

        return response(['message' => 'User not found'], 404);
    }

}