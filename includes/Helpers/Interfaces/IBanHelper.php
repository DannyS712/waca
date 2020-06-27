<?php
/******************************************************************************
 * Wikipedia Account Creation Assistance tool                                 *
 *                                                                            *
 * All code in this file is released into the public domain by the ACC        *
 * Development Team. Please see team.json for a list of contributors.         *
 ******************************************************************************/

namespace Waca\Helpers\Interfaces;

use Waca\DataObjects\Ban;
use Waca\DataObjects\Request;
use Waca\DataObjects\User;
use Waca\Helpers\BanHelper;
use Waca\Security\SecurityManager;

interface IBanHelper
{
    /**
     * @param Request $request
     *
     * @return bool
     */
    public function isBanned(Request $request): bool;

    /**
     * @param Request $request
     *
     * @return Ban[]
     */
    public function getBans(Request $request): array;

    public function canUnban(Ban $ban): bool;
}
