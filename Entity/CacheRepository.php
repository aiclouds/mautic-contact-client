<?php

/*
 * @copyright   2018 Mautic Contributors. All rights reserved
 * @author      Digital Media Solutions, LLC
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace MauticPlugin\MauticContactClientBundle\Entity;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Connections\MasterSlaveConnection;
use Doctrine\DBAL\Query\QueryBuilder;
use Mautic\CoreBundle\Entity\CommonRepository;
use Mautic\CoreBundle\Helper\PhoneNumberHelper;
use Mautic\LeadBundle\Entity\Lead as Contact;

/**
 * Class CacheRepository.
 */
class CacheRepository extends CommonRepository
{
    const MATCHING_ADDRESS  = 16;

    const MATCHING_EMAIL    = 2;

    const MATCHING_EXPLICIT = 1;

    const MATCHING_MOBILE   = 8;

    const MATCHING_PHONE    = 4;

    const SCOPE_CATEGORY    = 2;

    const SCOPE_GLOBAL      = 1;

    const SCOPE_UTM_SOURCE  = 4;

    /** @var PhoneNumberHelper */
    protected $phoneHelper;

    /**
     * Given a matching pattern and a contact, find any exceeded limits (aka caps/budgets).
     *
     * @param ContactClient  $contactClient
     * @param array          $rules
     * @param null           $timezone
     * @param \DateTime|null $dateSend
     *
     * @return array|null
     *
     * @throws \Exception
     */
    public function findLimit(
        ContactClient $contactClient,
        $rules = [],
        $timezone = null,
        \DateTime $dateSend = null
    ) {
        $filters = [];
        $result  = null;
        foreach ($rules as $rule) {
            $orx      = [];
            $value    = $rule['value'];
            $scope    = $rule['scope'];
            $duration = $rule['duration'];
            $quantity = $rule['quantity'];

            // Scope UTM Source
            if ($scope & self::SCOPE_UTM_SOURCE) {
                $utmSource = trim($value);
                if (!empty($utmSource)) {
                    $orx['utm_source'] = trim($value);
                }
            }

            // Scope Category
            if ($scope & self::SCOPE_CATEGORY) {
                $category = intval($value);
                if ($category) {
                    $orx['category_id'] = $category;
                }
            }

            // Match duration (always, including global scope)
            $filters[] = [
                'orx'              => $orx,
                'date_added'       => $this->oldestDateAdded($duration, $timezone, $dateSend)->getTimestamp(),
                'contactclient_id' => $contactClient->getId(),
            ];

            // Run the query to get the count.
            $count = $this->applyFilters($filters, true);
            if ($count > $quantity) {
                // Return the object, plus the count generated by the query.
                $result = [
                    'rule'  => $rule,
                    'count' => $count,
                ];
                break;
            }
        }

        return $result;
    }

    /**
     * Support non-rolling durations when P is not prefixing.
     *
     * @param                $duration
     * @param string|null    $timezone
     * @param \DateTime|null $dateSend
     *
     * @return \DateTime
     * @throws \Exception
     */
    public function oldestDateAdded($duration, string $timezone = null, \DateTime $dateSend = null)
    {
        if (!$timezone) {
            $timezone = date_default_timezone_get();
        }
        if ($dateSend) {
            $oldest = new \DateTime($dateSend->getTimestamp(), $timezone);
        } else {
            $oldest = new \DateTime('now', $timezone);
        }
        if (0 !== strpos($duration, 'P')) {
            // Non-rolling interval, go to previous interval segment.
            // Will only work for simple (singular) intervals.
            switch (strtoupper(substr($duration, -1))) {
                case 'Y':
                    $oldest->modify('next year jan 1 midnight');
                    break;
                case 'M':
                    $oldest->modify('first day of next month midnight');
                    break;
                case 'W':
                    $oldest->modify('sunday next week midnight');
                    break;
                case 'D':
                    $oldest->modify('tomorrow midnight');
                    break;
            }
            // Add P so that we can now use standard interval
            $duration = 'P'.$duration;
        }
        try {
            $interval = new \DateInterval($duration);
        } catch (\Exception $e) {
            // Default to monthly if the interval is faulty.
            $interval = new \DateInterval('P1M');
        }
        $oldest->sub($interval);

        return $oldest;
    }

    /**
     * @param array $filters
     * @param bool  $returnCount
     *
     * @return mixed|null
     */
    private function applyFilters($filters = [], $returnCount = false)
    {
        $result = null;
        // Convert our filters into a query.
        if ($filters) {
            $alias = $this->getTableAlias();
            $query = $this->slaveQueryBuilder();
            if ($returnCount) {
                $query->select('COUNT(*)');
            } else {
                // Selecting only the id and contact_id for covering index benefits.
                $query->select($alias.'.id, '.$alias.'.contact_id');
                $query->setMaxResults(1);
            }
            $query->from(MAUTIC_TABLE_PREFIX.$this->getTableName(), $alias);

            foreach ($filters as $k => $set) {
                // Expect orx, anx, or neither.
                if (isset($set['orx'])) {
                    if (!empty($set['orx'])) {
                        $expr = $query->expr()->orX();
                    }
                    $properties = $set['orx'];
                } elseif (isset($set['andx'])) {
                    if (!empty($set['andx'])) {
                        $expr = $query->expr()->andX();
                    }
                    $properties = $set['andx'];
                } else {
                    if (!isset($expr)) {
                        $expr = $query->expr()->andX();
                    }
                    $properties = $set;
                }
                if (isset($expr) && !empty($properties)) {
                    foreach ($properties as $property => $value) {
                        if (is_array($value)) {
                            $expr->add(
                                $query->expr()->andX(
                                    $query->expr()->isNotNull($alias.'.'.$property),
                                    $query->expr()->in($alias.'.'.$property, $value)
                                )
                            );
                        } elseif (is_int($value) || is_string($value)) {
                            if (!empty($value)) {
                                $expr->add(
                                    $query->expr()->andX(
                                        $query->expr()->isNotNull($alias.'.'.$property),
                                        $query->expr()->eq($alias.'.'.$property, ':'.$property.$k)
                                    )
                                );
                            } else {
                                $expr->add(
                                    $query->expr()->eq($alias.'.'.$property, ':'.$property.$k)
                                );
                            }
                            if (in_array($property, ['category_id', 'contact_id', 'campaign_id', 'contactclient_id'])) {
                                // Explicit integers for faster queries.
                                $query->setParameter($property.$k, (int) $value, \PDO::PARAM_INT);
                            } else {
                                $query->setParameter($property.$k, $value);
                            }
                        }
                    }
                }
                if (isset($set['exclusive_expire_date'])) {
                    // Expiration/Exclusions will require an extra outer AND expression.
                    if (!isset($exprOuter)) {
                        $exprOuter  = $query->expr()->orX();
                        $expireDate = $set['exclusive_expire_date'];
                    }
                    if (isset($expr)) {
                        $exprOuter->add(
                            $query->expr()->orX($expr)
                        );
                    }
                } elseif (isset($set['contactclient_id']) && isset($set['date_added'])) {
                    $query->add(
                        'where',
                        $query->expr()->andX(
                            $query->expr()->eq($alias.'.contactclient_id', ':contactClientId'.$k),
                            $query->expr()->gte($alias.'.date_added', 'FROM_UNIXTIME(:dateAdded'.$k.')'),
                            (isset($expr) ? $expr : null)
                        )
                    );
                    $query->setParameter('contactClientId'.$k, (int) $set['contactclient_id'], \PDO::PARAM_INT);
                    $query->setParameter('dateAdded'.$k, $set['date_added']);
                }
            }
            // Expiration can always be applied globally.
            if (isset($exprOuter) && isset($expireDate)) {
                $query->add(
                    'where',
                    $query->expr()->andX(
                        $query->expr()->isNotNull($alias.'.exclusive_expire_date'),
                        $query->expr()->gte($alias.'.exclusive_expire_date', 'FROM_UNIXTIME(:exclusiveExpireDate)'),
                        $exprOuter
                    )
                );
                $query->setParameter('exclusiveExpireDate', $expireDate);
            }

            $result = $query->execute()->fetch();
            if ($returnCount) {
                $result = intval(reset($result));
            }
        }

        return $result;
    }

    /**
     * Create a DBAL QueryBuilder preferring a slave connection if available.
     *
     * @return QueryBuilder
     */
    private function slaveQueryBuilder()
    {
        /** @var Connection $connection */
        $connection = $this->getEntityManager()->getConnection();
        if ($connection instanceof MasterSlaveConnection) {
            // Prefer a slave connection if available.
            $connection->connect('slave');
        }

        return new QueryBuilder($connection);
    }

    /**
     * Given a matching pattern and a contact, discern if there is a match in the cache.
     * Used for exclusivity and duplicate checking.
     *
     * @param Contact        $contact
     * @param ContactClient  $contactClient
     * @param array          $rules
     * @param string         $utmSource
     * @param string|null    $timezone
     * @param \DateTime|null $dateSend
     *
     * @return mixed|null
     *
     * @throws \Exception
     */
    public function findDuplicate(
        Contact $contact,
        ContactClient $contactClient,
        $rules = [],
        string $utmSource = null,
        string $timezone = null,
        \DateTime $dateSend = null
    ) {
        // Generate our filters based on the rules provided.
        $filters = [];
        foreach ($rules as $rule) {
            $orx      = [];
            $matching = $rule['matching'];
            $scope    = $rule['scope'];
            $duration = $rule['duration'];

            // Match explicit
            if ($matching & self::MATCHING_EXPLICIT) {
                $orx['contact_id'] = (int) $contact->getId();
            }

            // Match email
            if ($matching & self::MATCHING_EMAIL) {
                $email = trim($contact->getEmail());
                if ($email) {
                    $orx['email'] = $email;
                }
            }

            // Match phone
            if ($matching & self::MATCHING_PHONE) {
                $phone = $this->phoneValidate($contact->getPhone());
                if (!empty($phone)) {
                    $orx['phone'] = $phone;
                }
            }

            // Match mobile
            if ($matching & self::MATCHING_MOBILE) {
                $mobile = $this->phoneValidate($contact->getMobile());
                if (!empty($mobile)) {
                    $orx['mobile'] = $mobile;
                }
            }

            // Match address
            if ($matching & self::MATCHING_ADDRESS) {
                $address1 = trim(ucwords($contact->getAddress1()));
                if (!empty($address1)) {
                    $city    = trim(ucwords($contact->getCity()));
                    $zipcode = trim(ucwords($contact->getZipcode()));

                    // Only support this level of matching if we have enough for a valid address.
                    if (!empty($zipcode) || !empty($city)) {
                        $orx['address1'] = $address1;

                        $address2 = trim(ucwords($contact->getAddress2()));
                        if (!empty($address2)) {
                            $orx['address2'] = $address2;
                        }

                        if (!empty($city)) {
                            $orx['city'] = $city;
                        }

                        $state = trim(ucwords($contact->getState()));
                        if (!empty($state)) {
                            $orx['state'] = $state;
                        }

                        if (!empty($zipcode)) {
                            $orx['zipcode'] = $zipcode;
                        }

                        $country = trim(ucwords($contact->getCountry()));
                        if (!empty($country)) {
                            $orx['country'] = $country;
                        }
                    }
                }
            }

            // Scope UTM Source
            if ($scope & self::SCOPE_UTM_SOURCE) {
                if (!empty($utmSource)) {
                    $orx['utm_source'] = $utmSource;
                }
            }

            // Scope Category
            if ($scope & self::SCOPE_CATEGORY) {
                $category = $contactClient->getCategory();
                if ($category) {
                    $category = (int) $category->getId();
                    if (!empty($category)) {
                        $orx['category_id'] = $category;
                    }
                }
            }

            if ($orx) {
                // Match duration (always), once all other aspects of the query are ready.
                $filters[] = [
                    'orx'              => $orx,
                    'date_added'       => $this->oldestDateAdded($duration, $timezone, $dateSend)->getTimestamp(),
                    'contactclient_id' => $contactClient->getId(),
                ];
            }
        }

        return $this->applyFilters($filters);
    }

    /**
     * @param $phone
     *
     * @return string
     */
    private function phoneValidate($phone)
    {
        $result = null;
        $phone  = trim($phone);
        if (!empty($phone)) {
            if (!$this->phoneHelper) {
                $this->phoneHelper = new PhoneNumberHelper();
            }
            try {
                $phone = $this->phoneHelper->format($phone);
                if (!empty($phone)) {
                    $result = $phone;
                }
            } catch (\Exception $e) {
            }
        }

        return $result;
    }

    /**
     * Check the entire cache for matching contacts given all possible Exclusivity rules.
     *
     * Only the first 4 matching rules are allowed for exclusivity (by default).
     * Only the first two scopes are allowed for exclusivity.
     *
     * @param Contact        $contact
     * @param ContactClient  $contactClient
     * @param \DateTime|null $dateSend
     * @param int            $matching
     * @param int            $scope
     *
     * @return mixed|null
     *
     * @throws \Exception
     */
    public function findExclusive(
        Contact $contact,
        ContactClient $contactClient,
        \DateTime $dateSend = null,
        $matching = self::MATCHING_EXPLICIT | self::MATCHING_EMAIL | self::MATCHING_PHONE | self::MATCHING_MOBILE,
        $scope = self::SCOPE_GLOBAL | self::SCOPE_CATEGORY
    ) {
        // Generate our filters based on all rules possibly in play.
        $filters = [];

        // Match explicit
        if ($matching & self::MATCHING_EXPLICIT) {
            $filters[] = [
                'andx' => [
                    'contact_id'        => (int) $contact->getId(),
                    'exclusive_pattern' => $this->bitwiseIn($matching, self::MATCHING_EXPLICIT),
                ],
            ];
        }

        // Match email
        if ($matching & self::MATCHING_EMAIL) {
            $email = trim($contact->getEmail());
            if ($email) {
                $filters[] = [
                    'andx' => [
                        'email'             => $email,
                        'exclusive_pattern' => $this->bitwiseIn($matching, self::MATCHING_EMAIL),
                    ],
                ];
            }
        }

        // Match phone
        if ($matching & self::MATCHING_PHONE) {
            $phone = $this->phoneValidate($contact->getPhone());
            if (!empty($phone)) {
                $filters[] = [
                    'andx' => [
                        'phone'             => $phone,
                        'exclusive_pattern' => $this->bitwiseIn($matching, self::MATCHING_MOBILE),
                    ],
                ];
            }
        }

        // Match mobile
        if ($matching & self::MATCHING_MOBILE) {
            $mobile = $this->phoneValidate($contact->getMobile());
            if (!empty($mobile)) {
                $filters[] = [
                    'andx' => [
                        'phone'             => $mobile,
                        'exclusive_pattern' => $this->bitwiseIn($matching, self::MATCHING_PHONE),
                    ],
                ];
            }
        }

        // Due to high overhead, we've left out address-based exclusivity for now.
        // Match address
        if ($matching & self::MATCHING_ADDRESS) {
            $address1 = trim(ucwords($contact->getAddress1()));
            if (!empty($address1)) {
                $filter  = [];
                $city    = trim(ucwords($contact->getCity()));
                $zipcode = trim(ucwords($contact->getZipcode()));

                // Only support this level of matching if we have enough for a valid address.
                if (!empty($zipcode) || !empty($city)) {
                    $filter['address1'] = $address1;

                    $address2 = trim(ucwords($contact->getAddress2()));
                    if (!empty($address2)) {
                        $filter['address2'] = $address2;
                    }

                    if (!empty($city)) {
                        $filter['city'] = $city;
                    }

                    $state = trim(ucwords($contact->getState()));
                    if (!empty($state)) {
                        $filter['state'] = $state;
                    }

                    if (!empty($zipcode)) {
                        $filter['zipcode'] = $zipcode;
                    }

                    $country = trim(ucwords($contact->getCountry()));
                    if (!empty($country)) {
                        $filter['country'] = $country;
                    }

                    $filter['exclusive_pattern'] = $this->bitwiseIn($matching, self::MATCHING_ADDRESS);
                    $filters[]                   = [
                        'andx' => $filter,
                    ];
                }
            }
        }

        // Scope Global (added to all filters)
        if ($scope & self::SCOPE_GLOBAL) {
            $scopePattern = $this->bitwiseIn($scope, self::SCOPE_GLOBAL);
            foreach ($filters as &$filter) {
                $filter['andx']['exclusive_scope'] = $scopePattern;
            }
            unset($filter);
        }

        // Scope Category (duplicates all filters with category specificity)
        if ($scope & self::SCOPE_CATEGORY) {
            $category = $contactClient->getCategory();
            if ($category) {
                $category = (int) $category->getId();
                if ($category) {
                    $scopePattern = $this->bitwiseIn($scope, self::SCOPE_CATEGORY);
                    $newFilters   = [];
                    foreach ($filters as $filter) {
                        // Add existing filter.
                        $newFilters[serialize($filter)] = $filter;
                        // Create a new category-locked filter equivalent.
                        $newFilter                            = $filter;
                        $newFilter['andx']['category_id']     = $category;
                        $newFilter['andx']['exclusive_scope'] = $scopePattern;
                        $newFilters[serialize($newFilter)]    = $newFilter;
                    }
                    $filters = array_values($newFilters);
                }
            }
        }
        // Add expiration to all filters.
        $this->addExpiration($filters, $dateSend);

        return $this->applyFilters($filters);
    }

    /**
     * Given bitwise operators, and the value we want to match against,
     * generate a minimal array for an IN query.
     *
     * @param int $max
     * @param     $matching
     *
     * @return array
     */
    private function bitwiseIn(
        $max,
        $matching
    ) {
        $result = [];
        for ($i = 1; $i <= $max; ++$i) {
            if ($i & $matching) {
                $result[] = $i;
            }
        }

        return $result;
    }

    /**
     * Add Exclusion Expiration date.
     *
     * @param array          $filters
     * @param \DateTime|null $dateSend
     *
     * @throws \Exception
     */
    private function addExpiration(
        &$filters = [],
        \DateTime $dateSend = null
    ) {
        if ($filters) {
            $expiration = $dateSend ? $dateSend : new \DateTime();
            $expiration = $expiration->getTimestamp();
            foreach ($filters as &$filter) {
                $filter['exclusive_expire_date'] = $expiration;
            }
        }
    }

    /**
     * Delete all Cache entities that are no longer needed for duplication/exclusivity/limit checks.
     *
     * @throws \Exception
     */
    public function deleteExpired()
    {
        // General expirations. Maximum limiter is 1m.
        $oldest = new \DateTime('-1 month -1 day');
        $q      = $this->getEntityManager()->getConnection()->createQueryBuilder();
        $q->delete(MAUTIC_TABLE_PREFIX.$this->getTableName());
        $q->where(
            $q->expr()->lt('date_added', 'FROM_UNIXTIME(:oldest)')
        );
        $q->setParameter('oldest', $oldest->getTimestamp());
        $q->execute();
    }

    /**
     * Update exclusivity rows to reduce the index size and thus reduce processing required to check exclusivity.
     */
    public function reduceExclusivityIndex()
    {
        $q = $this->getEntityManager()->getConnection()->createQueryBuilder();
        $q->update(MAUTIC_TABLE_PREFIX.$this->getTableName());
        $q->where(
            $q->expr()->isNotNull('exclusive_expire_date'),
            $q->expr()->lte('exclusive_expire_date', 'NOW()')
        );
        $q->set('exclusive_expire_date', 'NULL');
        $q->set('exclusive_pattern', 'NULL');
        $q->set('exclusive_scope', 'NULL');
        $q->execute();
    }
}
