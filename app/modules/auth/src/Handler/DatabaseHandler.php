<?php

namespace Pagekit\Auth\Handler;

use Pagekit\Cookie\CookieJar;
use Pagekit\Database\Connection;
use RandomLib\Generator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

class DatabaseHandler implements HandlerInterface
{
    const STATUS_INACTIVE   = 0;
    const STATUS_ACTIVE     = 1;
    const STATUS_REMEMBERED = 2;

    /**
     * @var array
     */
    protected $config;

    /**
     * @var CookieJar
     */
    protected $cookie;

    /**
     * @var RequestStack
     */
    protected $requests;

    /**
     * @var Connection
     */
    protected $connection;

    /**
     * @var Generator
     */
    protected $random;

    /**
     * Constructor.
     *
     * @param Connection   $connection
     * @param RequestStack $requests
     * @param CookieJar    $cookie
     * @param Generator    $random
     * @param array        $config
     */
    public function __construct(Connection $connection, RequestStack $requests, CookieJar $cookie, Generator $random, $config = null)
    {
        $this->connection = $connection;
        $this->requests = $requests;
        $this->cookie = $cookie;
        $this->random = $random;
        $this->config = $config;
    }

    /**
     * {@inheritdoc}
     */
    public function find()
    {
        if ($token = $this->getToken()
            and $data = $this->connection->executeQuery("SELECT user_id, status, access FROM {$this->config['table']} WHERE id = :id AND status > :status", [
                'id' => sha1($token),
                'status' => self::STATUS_INACTIVE
            ])->fetch(\PDO::FETCH_ASSOC)
        ) {
            if (strtotime($data['access']) + $this->config['timeout'] < time()) {

                if ($data['status'] == self::STATUS_REMEMBERED) {
                    $this->set($data['user_id'], self::STATUS_REMEMBERED);
                } else {
                    return null;
                }

            }

            $this->connection->update($this->config['table'], ['access' => date('Y-m-d H:i:s')], ['id' => sha1($token)]);

            return $data['user_id'];
        }

        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function set($user, $remember = false)
    {
        if ($token = $this->getToken()) {
            $this->connection->delete($this->config['table'], ['id' => sha1($token)]);
        }

        $id = $this->random->generateString(64);

        $this->cookie->set($this->config['cookie']['name'], $id, $this->config['cookie']['lifetime'] + time());

        $this->connection->insert($this->config['table'], [
            'id' => sha1($id),
            'user_id' => $user,
            'access' => date('Y-m-d H:i:s'),
            'status' => $remember ? self::STATUS_REMEMBERED : self::STATUS_ACTIVE,
            'data' => json_encode([
                'ip' => $this->getRequest()->getClientIp(),
                'user-agent' => $this->getRequest()->headers->get('User-Agent')
            ])
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function remove()
    {
        if ($token = $this->getToken()) {
            $this->connection->update($this->config['table'], ['status' => 0], ['id' => sha1($token)]);
        }
    }

    /**
     * Gets the token from the request.
     *
     * @return mixed
     */
    protected function getToken()
    {
        return $this->getRequest()->cookies->get($this->config['cookie']['name']);
    }

    /**
     * @return null|Request
     */
    protected function getRequest()
    {
        return $this->requests->getCurrentRequest();
    }
}
