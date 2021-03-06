<?php
/**
 *
 * ============================================================================
 * [Innovation Framework] Copyright (c) 1995-2028 www.thinkimf.com;
 * 版权所有 1995-2028 陈建华/陈炼/DyoungChen/Dyoung【中国】，并保留所有权利。
 * This is not  free soft ware, use is subject to license.txt
 * 网站地址: http://www.thinkimf.com;
 * ----------------------------------------------------------------------------
 * 这不是一个自由软件！您只能在不用于商业目的的前提下对程序代码进行修改和
 * 使用；作者是一个还要还房贷的码农,请尊重作者的劳动成果,商业用途和技术支持请联系QQ:1367784103。
 * ============================================================================
 * $Author: 陈建华 $
 * $Create Time: 2018/2/9 0009 $
 * email:dyoungchen@gmail.com
 * function:auth.php
 */

namespace think\model\relation;

use think\Loader;
use think\Model;

class BelongsTo extends OneToOne
{
    /**
     * 架构函数
     * @access public
     * @param  Model  $parent 上级模型对象
     * @param  string $model 模型名
     * @param  string $foreignKey 关联外键
     * @param  string $localKey 关联主键
     * @param  string $relation  关联名
     */
    public function __construct(Model $parent, $model, $foreignKey, $localKey, $relation = null)
    {
        $this->parent     = $parent;
        $this->model      = $model;
        $this->foreignKey = $foreignKey;
        $this->localKey   = $localKey;
        $this->joinType   = 'INNER';
        $this->query      = (new $model)->db();
        $this->relation   = $relation;
    }

    /**
     * 延迟获取关联数据
     * @access public
     * @param  string   $subRelation 子关联名
     * @param  \Closure $closure     闭包查询条件
     * @return Model
     */
    public function getRelation($subRelation = '', $closure = null)
    {
        if ($closure) {
            $closure($this->query);
        }

        $foreignKey = $this->foreignKey;

        $relationModel = $this->query
            ->where($this->localKey, $this->parent->$foreignKey)
            ->relation($subRelation)
            ->find();

        if ($relationModel) {
            $relationModel->setParent(clone $this->parent);
        }

        return $relationModel;
    }

    /**
     * 根据关联条件查询当前模型
     * @access public
     * @param  string  $operator 比较操作符
     * @param  integer $count    个数
     * @param  string  $id       关联表的统计字段
     * @return Query
     */
    public function has($operator = '>=', $count = 1, $id = '*')
    {
        return $this->parent;
    }

    /**
     * 根据关联条件查询当前模型
     * @access public
     * @param  mixed     $where  查询条件（数组或者闭包）
     * @param  mixed     $fields 字段
     * @return Query
     */
    public function hasWhere($where = [], $fields = null)
    {
        $table    = $this->query->getTable();
        $model    = basename(str_replace('\\', '/', get_class($this->parent)));
        $relation = basename(str_replace('\\', '/', $this->model));

        if (is_array($where)) {
            $this->getQueryWhere($where, $relation);
        }

        $fields = $this->getRelationQueryFields($fields, $model);

        return $this->parent->db()
            ->alias($model)
            ->field($fields)
            ->join([$table => $relation], $model . '.' . $this->foreignKey . '=' . $relation . '.' . $this->localKey, $this->joinType)
            ->where($where);
    }

    /**
     * 预载入关联查询（数据集）
     * @access protected
     * @param  array     $resultSet 数据集
     * @param  string    $relation 当前关联名
     * @param  string    $subRelation 子关联名
     * @param  \Closure  $closure 闭包
     * @return void
     */
    protected function eagerlySet(&$resultSet, $relation, $subRelation, $closure)
    {
        $localKey   = $this->localKey;
        $foreignKey = $this->foreignKey;

        $range = [];
        foreach ($resultSet as $result) {
            // 获取关联外键列表
            if (isset($result->$foreignKey)) {
                $range[] = $result->$foreignKey;
            }
        }

        if (!empty($range)) {
            $data = $this->eagerlyWhere([
                [$localKey, 'in', $range],
            ], $localKey, $relation, $subRelation, $closure);

            // 关联属性名
            $attr = Loader::parseName($relation);

            // 关联数据封装
            foreach ($resultSet as $result) {
                // 关联模型
                if (!isset($data[$result->$foreignKey])) {
                    $relationModel = null;
                } else {
                    $relationModel = $data[$result->$foreignKey];
                    $relationModel->setParent(clone $result);
                    $relationModel->isUpdate(true);
                }

                if (!empty($this->bindAttr)) {
                    // 绑定关联属性
                    $this->bindAttr($relationModel, $result);
                } else {
                    // 设置关联属性
                    $result->setRelation($attr, $relationModel);
                }
            }
        }
    }

    /**
     * 预载入关联查询（数据）
     * @access protected
     * @param  Model     $result 数据对象
     * @param  string    $relation 当前关联名
     * @param  string    $subRelation 子关联名
     * @param  \Closure  $closure 闭包
     * @return void
     */
    protected function eagerlyOne(&$result, $relation, $subRelation, $closure)
    {
        $localKey   = $this->localKey;
        $foreignKey = $this->foreignKey;

        $data = $this->eagerlyWhere([
            [$localKey, '=', $result->$foreignKey],
        ], $localKey, $relation, $subRelation, $closure);

        // 关联模型
        if (!isset($data[$result->$foreignKey])) {
            $relationModel = null;
        } else {
            $relationModel = $data[$result->$foreignKey];
            $relationModel->setParent(clone $result);
            $relationModel->isUpdate(true);
        }

        if (!empty($this->bindAttr)) {
            // 绑定关联属性
            $this->bindAttr($relationModel, $result);
        } else {
            // 设置关联属性
            $result->setRelation(Loader::parseName($relation), $relationModel);
        }
    }

    /**
     * 添加关联数据
     * @access public
     * @param  Model $model       关联模型对象
     * @return Model
     */
    public function associate($model)
    {
        $foreignKey = $this->foreignKey;
        $pk         = $model->getPk();

        $this->parent->setAttr($foreignKey, $model->$pk);
        $this->parent->save();

        return $this->parent->setRelation($this->relation, $model);
    }

    /**
     * 注销关联数据
     * @access public
     * @return Model
     */
    public function dissociate()
    {
        $foreignKey = $this->foreignKey;

        $this->parent->setAttr($foreignKey, null);
        $this->parent->save();

        return $this->parent->setRelation($this->relation, null);
    }
}
