<?php

/* 
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */
namespace Game;
const STATUS_WAIT = 1;
const STATUS_PLAYING = 3;
const STATUS_FINISH = 4;

const STATUS_READY = 2;

class Server extends \swoole_websocket_server{
	
	protected $users = array();
	protected $server;
	protected $status = 0; //0等待 1开始 2进行中 3结束
	protected $user_pool = array(
		array(
			'uid' => 1,
			'nickname' => '钱等英',
			'head' => 'http://portrait2.sinaimg.cn/1600137382/blog/50',
		),
		array(
			'uid' => 2,
			'nickname' => '洪伟若',
			'head' => 'http://portrait2.sinaimg.cn/1084549485/blog/50',
		),
		array(
			'uid' => 3,
			'nickname' => '陈琰俊',
			'head' => 'http://portrait2.sinaimg.cn/2373950562/blog/50',
		),
		array(
			'uid' => 4,
			'nickname' => '傅吉廷',
			'head' => 'http://portrait2.sinaimg.cn/1369148850/blog/50',
		),
		array(
			'uid' => 5,
			'nickname' => '吴皑伊',
			'head' => 'http://portrait2.sinaimg.cn/1654967992/blog/50',
		),
		array(
			'uid' => 6,
			'nickname' => '李菊钦',
			'head' => 'http://portrait2.sinaimg.cn/1312054441/blog/50',
		),
		array(
			'uid' => 7,
			'nickname' => '柯奕福',
			'head' => 'http://portrait2.sinaimg.cn/1218323830/blog/50',
		),
		
	);

	public function __construct() {
		$this->server = new \swoole_websocket_server("0.0.0.0", 50030);
		$this->server->set(array(
			'worker_num' => 1,
		));
		
		$this->server->on('open', function ($ws, $request) {
			$this->onopen($ws, $request);
		});
		
		$this->server->on('message', function ($ws, $frame) {
			$this->onmessage($ws, $frame);
		});
		
		$this->server->on('close', function ($ws, $fd) {
			$this->onclose($ws, $fd);
		});
		
		$this->init();
	}
	
	protected function init(){
		$this->status = STATUS_WAIT;
		foreach(array_keys($this->users) as $uid){
			$this->users[$uid]['status'] = STATUS_WAIT;
		}
	}


	public function start(){
		$this->server->start();
	}
	protected function onmessage($ws, $frame){
		$data = json_decode($frame->data, true);
		$uid = $this->fd2uid($frame->fd);
		switch($data['action']){
			case 'do':
				$this->ondo($uid);
				break;
			case 'list_user':
				$this->list_user($frame->fd);
				break;
			case 'ready':
				$this->onready($uid);
				break;
			case 'logout':
				break;
		}
		$ws->push($frame->fd, "server: {$frame->data}");
	}
	
	protected function onclose($ws, $fd){
		$uid = $this->fd2uid($fd);
		if(!$uid){
			return;
		}
		$this->onlogout($uid);
	}
	
	protected function onlogout($uid){
		unset($this->users[$uid]);
		$this->broadcast(array(
			'action' => 'logout',
			'uid' => $uid,
		));
		
		//如果没人了，重置
		if(empty($this->users)){
			$this->init();
			return;
		}
		
		if($this->status == STATUS_PLAYING){
			$this->check_finish();
		}
		
		if($this->status == STATUS_WAIT){
			$this->check_ready();
		}
	}
	
	protected function onready($uid){
		if($this->status != STATUS_WAIT){
			return;
		}
		$this->users[$uid]['status'] = STATUS_READY;
		echo "User $uid is ready\n";
		$this->broadcast(array(
			'action' => 'ready',
			'uid' => $uid,
		));
		$this->check_ready();
	}
	
	/**
	 * 看看所有人都准备好了没
	 */
	protected function check_ready(){
		if($this->status != STATUS_WAIT){
			return;
		}
		foreach($this->users as $u){
			if($u['status'] != STATUS_READY){
				return;
			}
		}
		$this->status = STATUS_PLAYING;
		echo "Game started\n";
		$this->broadcast(array(
			'action' => 'begin',
		));
	}

	protected function fd2uid($fd){
		foreach($this->users as $uid => $user){
			if($user['fd'] == $fd){
				return $uid;
			}
		}
		return 0;
	}


	/**
	 * 用户操作了一下
	 */
	protected function ondo($uid){
		if($this->status != STATUS_PLAYING){
			return;
		}
		if($this->users[$uid]['status'] != STATUS_READY){
			return;
		}
		$point = $this->make_point();
		$this->users[$uid]['point'] = $point;
		$this->users[$uid]['status'] = STATUS_PLAYING;
		
		echo "User $uid shaked, and got point $point\n";
		$this->broadcast(array(
			'action' => 'do',
			'data' => $point,
			'uid' => $uid,
		));
		$this->check_finish();
	}
	
	protected function check_finish(){
		if(empty(array_filter($this->users, function($user){
			return $user['status'] == STATUS_READY;
		}))){
			$this->onfinish();
		}
	}

	protected function onfinish(){
		$max = 0;
		$max_uids = [];
		foreach($this->users as $uid => $user){
			if($user['point'] > $max){
				$max = $user['point'];
				$max_uids = [$uid];
			}elseif($user['point'] == $max){
				$max_uids[] = $uid;
			}
		}
		shuffle($max_uids);
		$max_uid = array_pop($max_uids);
		
		$this->broadcast(array(
			'action' => 'finish',
			'uid' => $max_uid,
		));
		
		echo sprintf("Game finished. Winner is %d, point is %d\n", $max_uid, $this->users[$max_uid]['point']);
		
		$this->init();
	}
	
	/**
	 * 生成一个点数
	 */
	protected function make_point(){
		return rand(1, 6);
	}

	protected function list_user($fd){
		$users = array();
		foreach($this->users as $user){
			$users[] = array(
				'uid' => $user['uid'],
				'nickname' => $user['nickname'],
				'head' => $user['head'],
			);
		}
		$this->server->push($fd, json_encode(array(
			'action' => 'list_user',
			'data' => $users,
		)));
	}
	
	protected function onopen($ws, $request){
		$user = array();
		foreach($this->user_pool as $u){
			if(!isset($this->users[$u['uid']])){
				$user = $u;
				break;
			}
		}
		if(!$user){
			echo "Too many users\n";
			$this->server->close($request->fd);
			return;
		}
		echo "User {$user['uid']} logined\n";
		$data = array(
			'uid' => $user['uid'],
			'nickname' => $user['nickname'],
			'head' => $user['head'],
			'fd' => $request->fd,
			'point' => 0,
			'status' => STATUS_WAIT,
		);
		$this->users[$user['uid']] = $data;
		
		$this->server->push($request->fd, json_encode(array(
			'action' => 'userinfo',
			'data' => array(
				'uid' => $user['uid'],
				'nickname' => $data['nickname'],
				'head' => $data['head'],
			),
		)));
		$this->broadcast(array(
			'action' => 'login',
			'data' => array(
				'uid' => $data['uid'],
				'nickname' => $data['nickname'],
				'head' => $data['head'],
			),
			'uid' => $user['uid'],
		), $request->fd);
		
		$this->list_user($request->fd);//发送玩家列表
	}
	
	protected function broadcast($data, $exp = 0){
		foreach($this->users as $user){
			if($user['fd'] != $exp){
				$this->server->push($user['fd'], json_encode($data));
			}
		}
	}
}

$server = new Server();
echo "Server is starting...\n";
$server->start();