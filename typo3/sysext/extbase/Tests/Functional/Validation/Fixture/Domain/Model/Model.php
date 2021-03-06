<?php
declare(strict_types = 1);
namespace TYPO3\CMS\Extbase\Tests\Functional\Validation\Fixture\Domain\Model;

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

use TYPO3\CMS\Extbase\DomainObject\AbstractEntity;

/**
 * Fixture model
 */
class Model extends AbstractEntity
{
    /**
     * @var string
     * @validate StringLength(minimum=1), StringLength(maximum=10)
     * @validate NotEmpty
     */
    protected $foo;

    /**
     * @var int
     * @validate \TYPO3\CMS\Extbase\Tests\Functional\Validation\Fixture\Validation\Validator\CustomValidator
     */
    protected $bar;

    /**
     * @var \TYPO3\CMS\Extbase\Persistence\ObjectStorage
     */
    protected $baz;

    /**
     * @var \TYPO3\CMS\Extbase\Tests\Functional\Validation\Fixture\Domain\Model\AnotherModel
     */
    protected $qux;
}
