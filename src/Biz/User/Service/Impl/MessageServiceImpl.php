<?php
namespace Biz\User\Service\Impl;

use Biz\BaseService;
use Topxia\Common\ArrayToolkit;
use Biz\User\Service\MessageService;

class MessageServiceImpl extends BaseService implements MessageService
{
    public function countMessages($conditions)
    {
        return $this->getMessageDao()->count($conditions);
    }

    public function searchMessages($conditions, $order, $start, $limit)
    {
        return $this->getMessageDao()->search($conditions, $order, $start, $limit);
    }

    public function sendMessage($fromId, $toId, $content, $type = 'text', $createdTime = null)
    {
        if (empty($fromId) || empty($toId)) {
            throw $this->createNotFoundException('Sender or Receiver Not Found');
        }

        if ($fromId == $toId) {
            throw $this->createAccessDeniedException('You\'re not allowed to send message to yourself');
        }

        if (empty($content)) {
            throw $this->createInvalidArgumentException('Message is Empty');
        }

        $createdTime = empty($createdTime) ? time() : $createdTime;
        $message     = $this->addMessage($fromId, $toId, $content, $type, $createdTime);
        $this->prepareConversationAndRelationForSender($message, $toId, $fromId, $createdTime);
        $this->prepareConversationAndRelationForReceiver($message, $fromId, $toId, $createdTime);
        $this->getUserService()->waveUserCounter($toId, 'newMessageNum', 1);
        return $message;
    }

    public function getConversation($conversationId)
    {
        return $this->getConversationDao()->get($conversationId);
    }

    public function deleteConversationMessage($conversationId, $messageId)
    {
        $relation     = $this->getRelationDao()->getByConversationIdAndMessageId($conversationId, $messageId);
        $conversation = $this->getConversationDao()->get($conversationId);

        if ($relation['isRead'] == MessageServiceImpl::RELATION_ISREAD_OFF) {
            $this->safelyUpdateConversationMessageNum($conversation);
            $this->safelyUpdateConversationunreadNum($conversation);
        } else {
            $this->safelyUpdateConversationMessageNum($conversation);
        }

        $this->getRelationDao()->deleteByConversationIdAndMessageId($conversationId, $messageId);
        $relationCount = $this->getRelationDao()->count(array('conversationId' => $conversationId));
        if ($relationCount == 0) {
            $this->getConversationDao()->delete($conversationId);
        }
    }

    public function deleteMessagesByIds(array $ids = null)
    {
        if (empty($ids)) {
            throw $this->createInvalidArgumentException("Invalid Argument");
        }
        foreach ($ids as $id) {
            $message      = $this->getMessageDao()->get($id);
            $conversation = $this->getConversationDao()->getByFromIdAndToId($message['fromId'], $message['toId']);
            if (!empty($conversation)) {
                $this->getRelationDao()->deleteByConversationIdAndMessageId($conversation['id'], $message['id']);
            }

            $conversation = $this->getConversationDao()->getByFromIdAndToId($message['toId'], $message['fromId']);
            if (!empty($conversation)) {
                $this->getRelationDao()->deleteByConversationIdAndMessageId($conversation['id'], $message['id']);
            }

            $this->getMessageDao()->delete($id);
        }
        return true;
    }

    public function findUserConversations($userId, $start, $limit)
    {
        return $this->getConversationDao()->searchByToId($userId, $start, $limit);
    }

    public function countUserConversations($userId)
    {
        return $this->getConversationDao()->count(array('toId' => $userId));
    }

    public function countConversationMessages($conversationId)
    {
        return $this->getRelationDao()->count(array('conversationId' => $conversationId));
    }

    public function deleteConversation($conversationId)
    {
        $this->getRelationDao()->deleteByConversationId($conversationId);
        return $this->getConversationDao()->delete($conversationId);
    }

    public function markConversationRead($conversationId)
    {
        $conversation = $this->getConversationDao()->get($conversationId);
        if (empty($conversation)) {
            throw $this->createNotFoundException("Conversation#{$conversationId} Not Found");
        }
        $updatedConversation = $this->getConversationDao()->update($conversation['id'], array('unreadNum' => 0));
        $this->getRelationDao()->updateByConversationId($conversationId, array('isRead' => 1));
        return $updatedConversation;
    }

    public function findConversationMessages($conversationId, $start, $limit)
    {
        $relations   = $this->getRelationDao()->searchByConversationId($conversationId, $start, $limit);
        $messages    = $this->getMessageDao()->findByIds(ArrayToolkit::column($relations, 'messageId'));
        $createUsers = $this->getUserService()->findUsersByIds(ArrayToolkit::column($messages, 'fromId'));

        foreach ($messages as &$message) {
            foreach ($createUsers as $createUser) {
                if ($createUser['id'] == $message['fromId']) {
                    $message['createdUser'] = $createUser;
                }
            }
        }
        return $this->sortMessages($messages);
    }

    public function clearUserNewMessageCounter($userId)
    {
        $this->getUserService()->clearUserCounter($userId, 'newMessageNum');
    }

    public function getConversationByFromIdAndToId($fromId, $toId)
    {
        return $this->getConversationDao()->getByFromIdAndToId($fromId, $toId);
    }

    protected function addMessage($fromId, $toId, $content, $type, $createdTime)
    {
        $message = array(
            'fromId'      => $fromId,
            'toId'        => $toId,
            'type'        => $type,
            'content'     => $this->biz['html_helper']->purify($content),
            'createdTime' => $createdTime
        );
        return $this->getMessageDao()->create($message);
    }

    protected function prepareConversationAndRelationForSender($message, $toId, $fromId, $createdTime)
    {
        $conversation = $this->getConversationDao()->getByFromIdAndToId($toId, $fromId);
        if ($conversation) {
            $this->getConversationDao()->update($conversation['id'], array(
                'messageNum'           => $conversation['messageNum'] + 1,
                'latestMessageUserId'  => $message['fromId'],
                'latestMessageContent' => $message['content'],
                'latestMessageTime'    => $message['createdTime'],
                'latestMessageType'    => $message['type']
            ));
        } else {
            $conversation = array(
                'fromId'               => $toId,
                'toId'                 => $fromId,
                'messageNum'           => 1,
                'latestMessageUserId'  => $message['fromId'],
                'latestMessageContent' => $message['content'],
                'latestMessageTime'    => $message['createdTime'],
                'latestMessageType'    => $message['type'],
                'unreadNum'            => 0,
                'createdTime'          => $createdTime
            );
            $conversation = $this->getConversationDao()->create($conversation);
        }

        $relation = array(
            'conversationId' => $conversation['id'],
            'messageId'      => $message['id'],
            'isRead'         => 0
        );
        $this->getRelationDao()->create($relation);
    }

    protected function prepareConversationAndRelationForReceiver($message, $fromId, $toId, $createdTime)
    {
        $conversation = $this->getConversationDao()->getByFromIdAndToId($fromId, $toId);
        if ($conversation) {
            $this->getConversationDao()->update($conversation['id'], array(
                'messageNum'           => $conversation['messageNum'] + 1,
                'latestMessageUserId'  => $message['fromId'],
                'latestMessageContent' => $message['content'],
                'latestMessageTime'    => $message['createdTime'],
                'unreadNum'            => $conversation['unreadNum'] + 1
            ));
        } else {
            $conversation = array(
                'fromId'               => $fromId,
                'toId'                 => $toId,
                'messageNum'           => 1,
                'latestMessageUserId'  => $message['fromId'],
                'latestMessageContent' => $message['content'],
                'latestMessageTime'    => $message['createdTime'],
                'unreadNum'            => 1,
                'createdTime'          => $createdTime
            );
            $conversation = $this->getConversationDao()->create($conversation);
        }
        $relation = array(
            'conversationId' => $conversation['id'],
            'messageId'      => $message['id'],
            'isRead'         => 0
        );
        $this->getRelationDao()->create($relation);
    }

    protected function safelyUpdateConversationMessageNum($conversation)
    {
        if ($conversation['messageNum'] <= 0) {
            $this->getConversationDao()->update($conversation['id'],
                array('messageNum' => 0));
        } else {
            $this->getConversationDao()->update($conversation['id'],
                array('messageNum' => $conversation['messageNum'] - 1));
        }
    }

    protected function safelyUpdateConversationunreadNum($conversation)
    {
        if ($conversation['unreadNum'] <= 0) {
            $this->getConversationDao()->update($conversation['id'],
                array('unreadNum' => 0));
        } else {
            $this->getConversationDao()->update($conversation['id'],
                array('unreadNum' => $conversation['unreadNum'] - 1));
        }
    }

    protected function sortMessages($messages)
    {
        usort($messages, function ($a, $b) {
            if ($a['createdTime'] > $b['createdTime']) {
                return -1;
            } elseif ($a['createdTime'] == $b['createdTime']) {
                return 0;
            } else {
                return 1;
            }
        });
        return $messages;
    }

    protected function getMessageDao()
    {
        return $this->createDao('User:MessageDao');
    }

    protected function getConversationDao()
    {
        return $this->createDao('User:MessageConversationDao');
    }

    protected function getRelationDao()
    {
        return $this->createDao('User:MessageRelationDao');
    }

    protected function getUserService()
    {
        return $this->biz->service('User:UserService');
    }

    protected function getSettingService()
    {
        return $this->biz->service('System:SettingService');
    }
}
