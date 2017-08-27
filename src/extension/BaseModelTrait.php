<?php
namespace rjapi\extension;

use rjapi\types\ModelsInterface;
use rjapi\types\PhpInterface;
use rjapi\types\RamlInterface;
use rjapi\helpers\SqlOptions;

trait BaseModelTrait
{
    /**
     * @param int $id
     * @param array $data
     *
     * @return mixed
     */
    private function getEntity(int $id, array $data = ModelsInterface::DEFAULT_DATA)
    {
        $obj = call_user_func_array(
            PhpInterface::BACKSLASH . $this->modelEntity . PhpInterface::DOUBLE_COLON
            . ModelsInterface::MODEL_METHOD_WHERE, [RamlInterface::RAML_ID, $id]
        );

        return $obj->first($data);
    }

    /**
     * @param string $modelEntity
     * @param int $id
     *
     * @return mixed
     */
    private function getModelEntity($modelEntity, int $id)
    {
        $obj = call_user_func_array(
            PhpInterface::BACKSLASH . $modelEntity . PhpInterface::DOUBLE_COLON
            . ModelsInterface::MODEL_METHOD_WHERE, [RamlInterface::RAML_ID, $id]
        );

        return $obj->first();
    }

    /**
     * @param string $modelEntity
     * @param array $params
     *
     * @return mixed
     */
    private function getModelEntities($modelEntity, array $params)
    {
        return call_user_func_array(
            PhpInterface::BACKSLASH . $modelEntity . PhpInterface::DOUBLE_COLON
            . ModelsInterface::MODEL_METHOD_WHERE, $params
        );
    }

    /**
     * Get rows from particular Entity
     *
     * @param SqlOptions $sqlOptions
     *
     * @return mixed
     */
    private function getAllEntities(SqlOptions $sqlOptions)
    {
        $limit = $sqlOptions->getLimit();
        $page = $sqlOptions->getPage();
        $data = $sqlOptions->getData();
        $orderBy = $sqlOptions->getOrderBy();
        $filter = $sqlOptions->getFilter();
        $defaultOrder = [];
        $order = [];
        $first = true;
        foreach($orderBy as $column => $value)
        {
            if($first === true)
            {
                $defaultOrder = [$column, $value];
            }
            else
            {
                $order[] = [ModelsInterface::COLUMN    => $column,
                    ModelsInterface::DIRECTION => $value];
            }
            $first = false;
        }
        $from = ($limit * $page) - $limit;
        $to = $limit * $page;
        $obj = call_user_func_array(
            PhpInterface::BACKSLASH . $this->modelEntity . PhpInterface::DOUBLE_COLON .
            ModelsInterface::MODEL_METHOD_ORDER_BY,
            $defaultOrder
        );
        // it can be empty if nothing more then 1st passed
        $obj->order = $order;

        $dataWithoutInclude = array_filter($data, function($v) { return (strpos($v,'include') === false); });

//dd($dataWithoutInclude);
        return $obj->where($filter)->take($limit)->skip($from)->get($dataWithoutInclude);

//        return $obj->where($filter)->take($to)->skip($from)->get($dataWithoutInclude);
    }
}