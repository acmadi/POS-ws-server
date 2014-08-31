<?php

namespace App;

use App\Stocks;
use App\Users;
use ORM\Category;
use ORM\CategoryQuery;
use ORM\Menu;
use ORM\MenuQuery;
use ORM\Option;
use ORM\OptionQuery;
use ORM\Product;
use ORM\ProductQuery;
use ORM\Role;
use ORM\RoleQuery;
use ORM\RolePermission;
use ORM\RolePermissionQuery;
use ORM\RowHistory;
use ORM\RowHistoryQuery;
use ORM\Stock;
use ORM\StockQuery;
use ORM\User;
use ORM\UserQuery;
use ORM\UserDetail;
use ORM\UserDetailQuery;
use Propel\Runtime\Propel;
use Ratchet\ConnectionInterface;
use Ratchet\MessageComponentInterface;
use Ratchet\Wamp\Exception;
use Util\ExceptionThrower;

class Mains implements MessageComponentInterface {
    protected $clients;

    public function __construct() {
        $this->clients = new \SplObjectStorage;
    }

    public function onOpen(ConnectionInterface $from) {
        $from->Session->start();

        // Store the new connection to send messages to later
        $this->clients->attach($from);

        echo "New connection! (res_id: {$from->resourceId}, {$from->Session->getName()}: {$from->Session->getId()})\n";
    }

    public function onClose(ConnectionInterface $conn) {
        require_once 'propel-config.php';
        $con = Propel::getConnection('pos');
        $con->rollBack();

        // The connection is closed, remove it, as we can no longer send it messages
        $this->clients->detach($conn);

        echo "Connection {$conn->resourceId} has disconnected\n";
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        require_once 'propel-config.php';
        $con = Propel::getConnection('pos');
        $con->rollBack();

        echo "Client {$conn->resourceId} hit an error: {$e->getMessage()} in {$e->getFile()} on line {$e->getLine()} \n";

        $data['success'] = false;
        $data['errmsg'] = $e->getMessage();

        $results['event'] = $conn->event;
        $results['data'] = $data;

        $conn->send(json_encode($results));
    }

    public function onMessage(ConnectionInterface $from, $msg) {
        $event = $from->event = 'anonymous';
        $data = [];

        // if message did not exist then deny access
        if (!isset($msg) || $msg == '') throw new Exception('Wrong turn buddy');

        // if event or data did not exist then deny access
        $msg = json_decode($msg);
        if (!$msg || !isset($msg->event) || !isset($msg->data)) throw new Exception('Wrong turn buddy');

        // store baby store em to variables
        $event = $from->event = $msg->event;
        $params = $msg->data;

        // if user not loged in yet then deny access
        if ($from->Session->get('pos/state') != 1) throw new Exception('Akses ditolak. Anda belum login.');

        // initiating propel orm
        require_once 'propel-config.php';
        Propel::disableInstancePooling();
        $con = Propel::getConnection('pos');
        
        // if user don't have role assigned then deny access
        $role = RoleQuery::create()->findOneById($from->Session->get('pos/current_user')->role_id);
        if (!$role) throw new Exception('Akses ditolak. Anda belum punya role.');

        // uh... decoding event to get requested module and method
        $decode = explode('/', $event);
        
        // if decoded array length is not 2 then deny access
        if (count($decode) != 2) throw new Exception('Wrong turn buddy');

        // store baby store em to variables... again
        $module = $decode[0];
        $method = $decode[1];

        // list of all module that can be requested
        $registeredModule = array(
            'combo',
            'pembelian',
            'penjualan',
            'stock',
            'user'
        );

        // if requested module is not registered then deny access
        if (!in_array($module, $registeredModule)) throw new Exception('Wrong turn buddy');

        // you know it.. begin transaction
        $con->beginTransaction();

        // this is where magic begins..
        // route to requested module
        $data = $this->$module($from, $method, $params, $con);

        // commit transaction
        $con->commit();

        // store to results variable before spitting it out back to client
        $results['event'] = $event;
        $results['data'] = $data;

        // errmmmmm
        $from->send(json_encode($results));
        
        // finish
        return;
    }

    private function combo($from, $method, $params, $con){
        $results = [];
        $time = time();

        $state = $from->Session->get('pos/state');
        $currentUser = (object) $from->Session->get('pos/currentUser');

        // check state
        if ($state != 1) throw new Exception('Akses ditolak. Anda belum login.');

        switch ($method){
            case 'barang':
                $arrSelect = [
                    'id',
                    'kode',
                    'nama'
                ];

                $barang = BarangQuery::create()
                    ->orderBy('nama', 'ASC');

                if(isset($params->query)){
                    $barang->condition('cond1', 'Barang.Nama like ?', "%$params->query%");
                    $barang->condition('cond2', 'Barang.Kode like ?', "%$params->query%");
                    $barang->where(array('cond1', 'cond2'), 'or');
                }
                $barang = $barang->select($arrSelect)
                    ->limit(20)
                    ->find($con);

                $arr = [];
                foreach($barang as $row) {
                    $arr[] = $row;
                }
                $results['success'] = true;
                $results['data'] = $arr;

                break;

            default:
                $results['success'] = false;
                $results['errmsg'] = 'Wrong turn buddy';
                break;
        }

        return $results;
    }

    private function pembelian($from, $method, $params, $con){
        $results = [];

        switch ($method){
            case 'create':
                break;
            case 'update':
                break;
            case 'destroy':
                break;
            default:
                $results['success'] = false;
                $results['errmsg'] = 'Wrong turn buddy';
                break;
        }

        return $results;
    }

    private function penjualan($from, $method, $params, $con){
        $results = [];

        switch ($method){
            case 'create':
                break;
            case 'update':
                break;
            case 'destroy':
                break;
            default:
                $results['success'] = false;
                $results['errmsg'] = 'Wrong turn buddy';
                break;
        }

        return $results;
    }

    private function stock($from, $method, $params, $con){
        $results = [];
                
        // list of all method that can be called in current module
        $registeredMethod = array(
            'create',
            'destroy',
            'loadFormEdit',
            'read',
            'update'
        );

        // if called method is not registered then deny access
        if (!in_array($method, $registeredMethod)) throw new Exception('Wrong turn buddy');

        // get Current User
        $currentUser = $from->Session->get('pos/current_user');

        // route to requested module and method
        $results = Stocks::$method($params, $currentUser, $con);

        return $results;
    }

    private function user($from, $method, $params, $con){
        $results = [];
                
        // list of all method that can be called in current module
        $registeredMethod = array(
            'create',
            'destroy',
            'loadFormEdit',
            'read',
            'resetPassword',
            'update'
        );

        // if called method is not registered then deny access
        if (!in_array($method, $registeredMethod)) throw new Exception('Wrong turn buddy');

        // get Current User
        $currentUser = $from->Session->get('pos/current_user');

        // route to requested module and method
        $results = Users::$method($params, $currentUser, $con);

        return $results;
    }

}