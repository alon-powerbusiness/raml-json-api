<?php
namespace rjapi\extension;

use Carbon\Carbon;

use Codeception\Lib\Connector\Guzzle;
use Faker\Provider\cs_CZ\DateTime;
use Google\Cloud\BigQuery\Job;
use Illuminate\Http\Request;
//use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

use Illuminate\Routing\Route;
use League\Flysystem\Exception;
use League\Fractal\Resource\Collection;
use Modules\V1\Entities\Gallery;
//use Psy\Configuration;
use rjapi\helpers\ConfigOptions;
use rjapi\helpers\Jwt;
use rjapi\types\ConfigInterface;
use rjapi\types\DirsInterface;
use rjapi\blocks\EntitiesTrait;
use rjapi\blocks\FileManager;
use rjapi\types\JwtInterface;
use rjapi\types\ModelsInterface;
use rjapi\types\RamlInterface;
use rjapi\helpers\Classes;
use rjapi\helpers\Config;
use rjapi\helpers\Json;
use rjapi\helpers\MigrationsHelper;
use rjapi\helpers\SqlOptions;
use rjapi\types\PhpInterface;
use Lcobucci\JWT\Parser;
use App\ActivationService;
use App\ActivationRepository;
use App\User;
//use Validator;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\QueryException;
//use GuzzleHttp\Psr7\Request;
use Google\Cloud\Vision\VisionClient;
use Google_Client;
use App\Jobs\UpdateTag;
use GuzzleHttp\Client;
use GuzzleHttp\Promise;


//Add protected variable:


/**
 * Class BaseControllerTrait
 *
 * @package rjapi\extension
 */
trait BaseControllerTrait
{
    use BaseModelTrait, EntitiesTrait;

    private $props = [];
    private $entity = null;
    /** @var BaseModel model */
    private $model = null;
    private $modelEntity = null;
    private $middleWare = null;
    private $relsRemoved = false;
    // default query params value
    private $defaultPage = 0;
    private $defaultLimit = 0;
    private $defaultSort = '';
    private $defaultOrderBy = [];
    /** @var ConfigOptions configOptions */
    private $configOptions = null;

    private $jsonApiMethods = [
        JSONApiInterface::URI_METHOD_INDEX,
        JSONApiInterface::URI_METHOD_VIEW,
        JSONApiInterface::URI_METHOD_CREATE,
        JSONApiInterface::URI_METHOD_UPDATE,
        JSONApiInterface::URI_METHOD_DELETE,
        JSONApiInterface::URI_METHOD_RELATIONS,
    ];

    private $jwtExcluded = [
        JwtInterface::JWT,
        JwtInterface::PASSWORD,
    ];

    /**
     * BaseControllerTrait constructor.
     *
     * @param Route $route
     */
    protected $activationService;
    protected $activationRepo;

    public function validator(array $data, array $rules,  array $error_messages)
    {
        //Validator::make($data,[]);
//dd($data);
        return Validator::make($data, $rules,$error_messages );


    }

    public function activateUser($token)
    {

        $activation = $this->activationRepo->getActivationByToken($token);

        if ($activation === null) {
            return null;
        }

        $user = User::find($activation->user_id);

        $user->activated = true;

        $user->save();

        $this->activationRepo->deleteActivation($token);

        return $user;

    }

    public function __construct(Route $route,ActivationService $activationService,ActivationRepository $activationRepository)
    {

        $this->middleware('guest');
        $this->activationService = $activationService;
        $this->activationRepo = $activationRepository;


        // add relations to json api methods array
        $this->addRelationMethods();
        $actionName = $route->getActionName();
        $calledMethod = substr($actionName, strpos($actionName, PhpInterface::AT) + 1);
        /** @var BaseController jsonApi */
        if($this->jsonApi === false && in_array($calledMethod, $this->jsonApiMethods))
        {
            Json::outputErrors(
                [
                    [
                        JSONApiInterface::ERROR_TITLE  => 'JSON API support disabled',
                        JSONApiInterface::ERROR_DETAIL => 'JSON API method ' . $calledMethod
                            .
                            ' was called. You can`t call this method while JSON API support is disabled.',
                    ],
                ]
            );
        }
        $this->setEntities();
        $this->setDefaults();
        $this->setConfigOptions();
    }


    public function refresh(Request $request )
    {

        try {
            if ($request->jwt) {

                $token = (new Parser())->parse((string)$request->jwt);
                $id = $token->getClaim("uid");

                $model = $this->getEntity($id);


                // we delete all the unnencesairy data and return only the jwt
                $uniqId = uniqid();

                $model->jwt = Jwt::create($model->id, $uniqId);


                $model->save();

                $model->id = null;
                $model->name = "";
                $model->password = "";
                $model->email = "";

                $resource = Json::getResource($this->middleWare, $model, $this->entity);

                Json::outputSerializedData($resource, JSONApiInterface::HTTP_RESPONSE_CODE_CREATED);

            }else {
                return response()->json([
                    "data" => ["type" => "error", "attributes" => ["title" => "Error", "desc" => "Invalid Token."]]
                ], 401);
            }
        } catch (\Exception $e) {

            return response()->json([
                "data" => ["type" => "error", "attributes" => ["title" => "Error", "desc" => "Fatal Error."]]
            ], 401);


        }


    }


    public function login(Request $request)
    {

        try {
            $json = Json::decode($request->getContent());
            $jsonApiAttributes = Json::getAttributes($json);
//dd($request);

            $obj = call_user_func_array(
                PhpInterface::BACKSLASH . $this->modelEntity . PhpInterface::DOUBLE_COLON
                . ModelsInterface::MODEL_METHOD_WHERE, ["email", $jsonApiAttributes["email"]]
            )->first();


            if (password_verify($jsonApiAttributes["password"], $obj->password) === false) {
                return response()->json([
                    "data" => ["type"=>"error","attributes"=>["title"=>"Error","desc"=>"Email or Password is invalid."]]
                ],401);

            } else {
                // we delete all the unnencesairy data and return only the jwt
                $uniqId = uniqid();

                $obj->jwt = Jwt::create($obj->id, $uniqId);
                $obj->save();

                $obj->id = null;
                //$obj->name = "";
                $obj->password = "";
                $obj->email = "";

                $resource = Json::getResource($this->middleWare, $obj, $this->entity);
                Json::outputSerializedData($resource, JSONApiInterface::HTTP_RESPONSE_CODE_CREATED);

            }
        }catch(\Exception $e){

            return response()->json([
                "data" => ["type"=>"error","attributes"=>["title"=>"Error","desc"=>$e]]
            ],401);


        }



    }


    public function getRenderSubQueueCount(Request $request)
    {

//        if ($request->jwt) {
        $sqlOptions = $this->setSqlOptions($request);
//            $token = (new Parser())->parse((string)$request->jwt);



//            $this->model->user_id = $token->getClaim("uid");

        $filter=[['render_queue_id','=', $request->render_queue_id],['status','=',$request->status]];

        //$data=["id","user_id","template_id","include.title","include.input_view","include.thumbnail_url"];



        $sqlOptions->setFilter($filter);
        //$sqlOptions->setData($data);



        $items = $this->getAllEntities($sqlOptions);

        $resource = Json::getResource($this->middleWare, $items, $this->entity, true);
        return response(count($items));

//            Json::outputSerializedData($resource, JSONApiInterface::HTTP_RESPONSE_CODE_OK, $sqlOptions->getData());

//        } else {
//            return response()->json([
//                "data" => ["type" => "error", "attributes" => ["title" => "Error", "desc" => "Email or Password is invalid."]]
//            ], 401);

//        }
    }


    public function getRenderActiveQueueCount(Request $request)
    {

//        if ($request->jwt) {
        $sqlOptions = $this->setSqlOptions($request);
//            $token = (new Parser())->parse((string)$request->jwt);



//            $this->model->user_id = $token->getClaim("uid");

        $filter=[['status','>', 0],['status','<',6]];

        //$data=["id","user_id","template_id","include.title","include.input_view","include.thumbnail_url"];


        $sqlOptions->setFilter($filter);
        //$sqlOptions->setData($data);



        $items = $this->getAllEntities($sqlOptions);


        if (count($items) < 2) {
            $filter=[['status','=', 0]];
            $sortby = ['id'=>'asc'];

//            dd($sortby);
//            dd(Json::decode($request->input(ModelsInterface::PARAM_ORDER_BY)));

            $sqlOptions->setFilter($filter);
            $sqlOptions->setLimit(1);
            $sqlOptions->setOrderBy($sortby);


            $status = 0;
            $status_text ='ready to render';
            $items = $this->getAllEntities($sqlOptions);
        }
        else
        {
            $status = 1;
            $status_text ='render in process';
        }
        //$resource = Json::getResource($this->middleWare, $items, $this->entity, true);


        // dd($items);
        return response()->json([

            "count" => count($items),
            "items" => $items,
            "status" => $status,
            "status_text"=>$status_text


        ]);



        //return response(count($items));


//            Json::outputSerializedData($resource, JSONApiInterface::HTTP_RESPONSE_CODE_OK, $sqlOptions->getData());

//        } else {
//            return response()->json([
//                "data" => ["type" => "error", "attributes" => ["title" => "Error", "desc" => "Email or Password is invalid."]]
//            ], 401);

//        }
    }


    //get duration for active queue render
    public function getRenderActiveAndQueueDurationSum(Request $request)
    {

        $sqlOptions = $this->setSqlOptions($request);


        $filter=[['status','>=', 0],['status','<',6]];

        $data=["duration_fps"];


        $sqlOptions->setFilter($filter);
        $sqlOptions->setData($data);



        $items = $this->getAllEntities($sqlOptions)->sum('duration_fps');

        return $items;

    }


    public function updateQueuePhantomJSCount(Request $request)
    {

        $model = $this->getEntity($request->id);

        $model->phantomjs_progress++;
        $model->save();

        return($model->phantomjs_progress);

    }


    // compare the user id and the firebase id and return all the matches
    public function FCMCompare(Request $request, int $id)
    {
        $sqlOptions = $this->setSqlOptions($request);

        $filter=[['uid','=', $id]];

        $sqlOptions->setFilter($filter);

        $items = $this->getAllEntities($sqlOptions);

        if(isset($items[0]))
        {

            // dd($items[0]->getAttributes());
            $arr = [];
            foreach($items as $item)
            {
                $arr[]=($item->getAttributes()["token"]);
            }

            return response()->json([
                "data" => ["type" => "FCM", "attributes" => ["title" => "Success", "desc" => $arr]]
            ],200);
        }
        else{
            return response()->json([
                "data" => ["type" => "FCM", "attributes" => ["title" => "NO Matches", "desc" => $items]]
            ],200);
        }

        //$resource = Json::getResource($this->middleWare, $items, $this->entity, true);



    }


    //native notification
    public function createFCM(Request $request)
    {

        try
        {
            $token = (new Parser())->parse((string)$request->jwt);

        }
        catch(Exception $err)
        {

            return response()->json([
                "data" => ["type" => "fcm_TokenResult", "attributes" => ["title" => "Error", "desc" =>  'Undefined Token']]
            ],401);
        }

        $id = $token->getClaim("uid");


        $json = Json::decode($request->getContent());


        $jsonApiAttributes = Json::getAttributes($json);
        foreach ($this->props as $k => $v) {
            // request fields should match Middleware fields
            if (isset($jsonApiAttributes[$k])) {
                $this->model->$k = $jsonApiAttributes[$k];
            }
        }

//TODO add funny phrase for email already taken


        //$this->model->token = str_random(4).time().str_random(8);
        //$this->model->user_id = $id;
        try{

            $this->model->uid = $id;
            $this->model->save();

        }
        catch(QueryException $ex)
        {
            return response()->json([
                "data" => ["type" => "fcm_TokenResult", "attributes" => ["title" => "Token already exists", "desc" =>  'Token already exists']]
            ],200);
            //dd ($ex->getMessage());
        }

//            $this->model = $model;
//            unset($this->model->password);
//        }
        $this->setRelationships($json, $this->model->id);
        $resource = Json::getResource($this->middleWare, $this->model, $this->entity);
//        Json::outputSerializedData($resource, JSONApiInterface::HTTP_RESPONSE_CODE_CREATED);

        return response()->json([
            "data" => ["type" => "fcm_TokenResult", "attributes" => ["title" => "Success", "desc" =>  $this->model->token]]
        ],200);


    }


    public function createUser(Request $request)
    {

        $json = Json::decode($request->getContent());


        $jsonApiAttributes = Json::getAttributes($json);
        foreach ($this->props as $k => $v) {
            // request fields should match Middleware fields
            if (isset($jsonApiAttributes[$k])) {
                $this->model->$k = $jsonApiAttributes[$k];
            }
        }
        $password = ($this->model->password);

//TODO add funny phrase for email already taken
        $error_messages=[
            'email.unique'=>'Opps funny error - email is already taken'
        ];
        $rules=[
            'name'=>'min:2|required',
            'email'=>'email|required|unique:users',
            'password'=>'between:6,30|required|regex:/^[A-Za-z0-9@!#\$%\^&\*]+$/'
        ];

        $validator = $this->validator($jsonApiAttributes, $rules, $error_messages);
        if ($validator->fails()) {
            $array =[] ;
            //dd($validator->messages()->toArray());
            // dd($validator->errors()->getMessages()["email"]);


            foreach ($validator->errors()->keys() as $test){
                //dd($validator->errors()->getMessages()[$test]);
                $array = array_add($array,$test ,$validator->errors()->getMessages()[$test][0]);

            }

            return response()->json([
                "data" => ["type" => "error", "attributes" => ["title" => "Error", "desc" => $array]]
            ], 401);

        }
        $user = $this->create($request,false);



        $this->activationService->sendActivationMail($user);

        // jwt
        if ($this->configOptions->getIsJwtAction() === true) {
            if (empty($jsonApiAttributes["password"])) {
                Json::outputErrors(
                    [
                        [
                            JSONApiInterface::ERROR_TITLE => 'Password should be provided',
                            JSONApiInterface::ERROR_DETAIL => 'To get refreshed token in future usage of application - user password should be provided',
                        ],
                    ]
                );
            }

            $uniqId = uniqid();
            $model = $this->getEntity($this->model->id);
            $model->jwt = Jwt::create($this->model->id, $uniqId);
            $model->password = password_hash($password, PASSWORD_DEFAULT);
            $model->save();
            $this->model = $model;
            unset($this->model->password);
        }
        $this->setRelationships($json, $this->model->id);
        $resource = Json::getResource($this->middleWare, $this->model, $this->entity);
        Json::outputSerializedData($resource, JSONApiInterface::HTTP_RESPONSE_CODE_CREATED);


    }

    /**
     * GET Output all entries for this Entity with page/limit pagination support
     *
     * @param Request $request
     */
    public function index(Request $request,$newSqlOptions=null)
    {

        $newSqlOptions!=null?$sqlOptions = $newSqlOptions:$sqlOptions=$this->setSqlOptions($request);
        $items = $this->getAllEntities($sqlOptions);
        $resource = Json::getResource($this->middleWare, $items, $this->entity, true);
        Json::outputSerializedData($resource, JSONApiInterface::HTTP_RESPONSE_CODE_OK, $sqlOptions->getData());
    }

    /**
     * GET Output one entry determined by unique id as uri param
     *
     * @param Request $request
     * @param int $id
     */
    public function view(Request $request,int $id, $sqlOptions=null)
    {

        $data = ($request->input(ModelsInterface::PARAM_DATA) === null) ? ModelsInterface::DEFAULT_DATA
            : json_decode(urldecode($request->input(ModelsInterface::PARAM_DATA)), true);
        $sqlOptions!=null?$item = $this->getAllEntities($sqlOptions):$item= $this->getEntity($id, $data);
        $resource = Json::getResource($this->middleWare, $item, $this->entity);
        Json::outputSerializedData($resource, JSONApiInterface::HTTP_RESPONSE_CODE_OK, $data);
    }

    /**
     * POST Creates one entry specified by all input fields in $request
     *
     * @param Request $request
     */

    public function addOrUpdateAttributes($jsonApiAttributes,$attributes)
    {
        foreach($attributes as $key=>$value)
        {
            $jsonApiAttributes[$key] = $value;
        }

        return $jsonApiAttributes;
    }


    //alonn
    public function create(Request $request,$attributes=null,bool $returnJSon = true)
    {


        $json = Json::decode($request->getContent());

        $jsonApiAttributes = Json::getAttributes($json);
        $attributes!=null&&$jsonApiAttributes = $this->addOrUpdateAttributes($jsonApiAttributes,$attributes);

        foreach($this->props as $k => $v)
        {
            // request fields should match Middleware fields
            if(isset($jsonApiAttributes[$k]))
            {
                $this->model->$k = $jsonApiAttributes[$k];
            }
        }

        //Get user_id auotmaticlty from JWT token.
        //Don't allow to manuel insert user_id
        if(isset($jsonApiAttributes["user_id"])){
            if(($jsonApiAttributes["user_id"]) === "JWT"){

                //get user_id from JWT token
                //$token2  = $json->jwt;
                $jwt = $request->jwt;

                //dd($token2);
                $token = (new Parser())->parse((string)$jwt);

                $this->model->user_id = $token->getClaim("uid");
            }

        }

        $this->model->save();



        // jwt
        if($this->configOptions->getIsJwtAction() === true)
        {
            if(empty($this->model->password))
            {
                Json::outputErrors(
                    [
                        [
                            JSONApiInterface::ERROR_TITLE  => 'Password should be provided',
                            JSONApiInterface::ERROR_DETAIL => 'To get refreshed token in future usage of application - user password should be provided',
                        ],
                    ]
                );
            }
            $uniqId = uniqid();
            $model = $this->getEntity($this->model->id);
            $model->jwt = Jwt::create($this->model->id, $uniqId);
            $model->password = password_hash($this->model->password, PASSWORD_DEFAULT);
            $model->save();
            $this->model = $model;
            unset($this->model->password);
        }
        $this->setRelationships($json, $this->model->id);
        $resource = Json::getResource($this->middleWare, $this->model, $this->entity);

        if ($returnJSon){
            Json::outputSerializedData($resource, JSONApiInterface::HTTP_RESPONSE_CODE_CREATED);
        }
        else
        {
            return($this->model);
        }
    }

    /**
     * PATCH Updates one entry determined by unique id as uri param for specified fields in $request
     *
     * @param Request $request
     * @param int $id
     */
    public function update(Request $request,int $id,$sqlOptions=null )
    {
        // get json raw input and parse attrs
        $json = Json::decode($request->getContent());
        $jsonApiAttributes = Json::getAttributes($json);
        $sqlOptions!=null?$model = $this->getAllEntities($sqlOptions)[0]:$model= $this->getEntity($id);


        //$model = $this->getEntity($id);
        // jwt
        if($this->configOptions->getIsJwtAction() === true && (bool)$jsonApiAttributes[JwtInterface::JWT] === true)
        {
            if(password_verify($jsonApiAttributes[JwtInterface::PASSWORD], $model->password) === false)
            {
                Json::outputErrors(
                    [
                        [
                            JSONApiInterface::ERROR_TITLE  => 'Password is invalid.',
                            JSONApiInterface::ERROR_DETAIL => 'To get refreshed token - pass the correct password',
                        ],
                    ]
                );
            }
            $uniqId = uniqid();
            $model->jwt = Jwt::create($model->id, $uniqId);
            unset($model->password);
        }
        else
        { // standard processing

            foreach($jsonApiAttributes as $k => $v){
                $model->$k = $jsonApiAttributes[$k];
            }


        }

        $model->save();
        $this->setRelationships($json, $model->id, true);
        $resource = Json::getResource($this->middleWare, $model, $this->entity);
        Json::outputSerializedData($resource);
    }

    /**
     * DELETE Deletes one entry determined by unique id as uri param
     *
     * @param int $id
     */
    public function delete(int $id)
    {
        $model = $this->getEntity($id);
        if($model !== null)
        {
            $model->delete();
        }
        Json::outputSerializedData(new Collection(), JSONApiInterface::HTTP_RESPONSE_CODE_NO_CONTENT);
    }

    /**
     * GET the relationships of this particular Entity
     *
     * @param Request $request
     * @param int $id
     * @param string $relation
     */
    public function relations(Request $request, int $id, string $relation)
    {
//        dd($id);
        $model = $this->getEntity($id);
        if(empty($model))
        {
            Json::outputErrors(
                [
                    [
                        JSONApiInterface::ERROR_TITLE => 'Database object ' . $this->entity . ' with $id = ' . $id .
                            ' - not found.',
                    ],
                ]
            );
        }
        $resource = Json::getRelations($model->$relation, $relation);
        Json::outputSerializedRelations($request, $resource);
    }

    /**
     * POST relationships for specific entity id
     *
     * @param Request $request
     * @param int $id
     * @param string $relation
     */
    public function createRelations(Request $request, int $id, string $relation)
    {
        $json = Json::decode($request->getContent());
        $this->setRelationships($json, $id);

        $_GET['include'] = $relation;
        $model = $this->getEntity($id);
        if(empty($model))
        {
            Json::outputErrors(
                [
                    [
                        JSONApiInterface::ERROR_TITLE => 'Database object ' . $this->entity . ' with $id = ' . $id .
                            ' - not found.',
                    ],
                ]
            );
        }
        $resource = Json::getResource($this->middleWare, $model, $this->entity);
        Json::outputSerializedData($resource);
    }

    /**
     * PATCH relationships for specific entity id
     *
     * @param Request $request
     * @param int $id
     * @param string $relation
     */
    public function updateRelations(Request $request, int $id, string $relation)
    {
        $json = Json::decode($request->getContent());
        $this->setRelationships($json, $id, true);
        // set include for relations
        $_GET['include'] = $relation;

        $model = $this->getEntity($id);
        if(empty($model))
        {
            Json::outputErrors(
                [
                    [
                        JSONApiInterface::ERROR_TITLE => 'Database object ' . $this->entity . ' with $id = ' . $id .
                            ' - not found.',
                    ],
                ]
            );
        }
        $resource = Json::getResource($this->middleWare, $model, $this->entity);
        Json::outputSerializedData($resource);
    }

    /**
     * DELETE relationships for specific entity id
     *
     * @param Request $request JSON API formatted string
     * @param int $id int id of an entity
     * @param string $relation
     */
    public function deleteRelations(Request $request, int $id, string $relation)
    {
        $json = Json::decode($request->getContent());
        $jsonApiRels = Json::getData($json);
        if(empty($jsonApiRels) === false)
        {
            $lowEntity = strtolower($this->entity);
            foreach($jsonApiRels as $index => $val)
            {
                $rId = $val[RamlInterface::RAML_ID];
                // if pivot file exists then save
                $ucEntity = ucfirst($relation);
                $file = DirsInterface::MODULES_DIR . PhpInterface::SLASH
                    . Config::getModuleName() . PhpInterface::SLASH .
                    DirsInterface::ENTITIES_DIR . PhpInterface::SLASH .
                    $this->entity . $ucEntity . PhpInterface::PHP_EXT;
                if(file_exists(PhpInterface::SYSTEM_UPDIR . $file))
                { // ManyToMany rel
                    $pivotEntity = Classes::getModelEntity($this->entity . $ucEntity);
                    // clean up old links
                    $this->getModelEntities(
                        $pivotEntity,
                        [
                            [
                                $lowEntity . PhpInterface::UNDERSCORE . RamlInterface::RAML_ID => $id,
                                $relation . PhpInterface::UNDERSCORE . RamlInterface::RAML_ID  => $rId,
                            ],
                        ]
                    )->delete();
                }
                else
                { // OneToOne/Many
                    $relEntity = Classes::getModelEntity($ucEntity);
                    $model = $this->getModelEntities(
                        $relEntity, [
                            $lowEntity . PhpInterface::UNDERSCORE . RamlInterface::RAML_ID, $id,
                        ]
                    );
                    $model->update([$relation . PhpInterface::UNDERSCORE . RamlInterface::RAML_ID => 0]);
                }
            }
            Json::outputSerializedData(new Collection(), JSONApiInterface::HTTP_RESPONSE_CODE_NO_CONTENT);
        }
    }

    /**
     * @param array $json
     * @param int $eId
     * @param bool $isRemovable
     */
    private function setRelationships(array $json, int $eId, bool $isRemovable = false)
    {
        $jsonApiRels = Json::getRelationships($json);
        if(empty($jsonApiRels) === false)
        {
            foreach($jsonApiRels as $entity => $value)
            {
                if(empty($value[RamlInterface::RAML_DATA][RamlInterface::RAML_ID]) === false)
                {
                    // if there is only one relationship
                    $rId = $value[RamlInterface::RAML_DATA][RamlInterface::RAML_ID];
                    $this->saveRelationship($entity, $eId, $rId, $isRemovable);
                }
                else
                {
                    // if there is an array of relationships
                    foreach($value[RamlInterface::RAML_DATA] as $index => $val)
                    {
                        $rId = $val[RamlInterface::RAML_ID];
                        $this->saveRelationship($entity, $eId, $rId, $isRemovable);
                    }
                }
            }
        }
    }

    /**
     * @param      $entity
     * @param int $eId
     * @param int $rId
     * @param bool $isRemovable
     */
    private function saveRelationship($entity, int $eId, int $rId, bool $isRemovable = false)
    {
        $ucEntity = Classes::getClassName($entity);
        $lowEntity = MigrationsHelper::getTableName($this->entity);
        // if pivot file exists then save
        $filePivot = FileManager::getPivotFile($this->entity, $ucEntity);
        $filePivotInverse = FileManager::getPivotFile($ucEntity, $this->entity);
        $pivotExists = file_exists(PhpInterface::SYSTEM_UPDIR . $filePivot);
        $pivotInverseExists = file_exists(PhpInterface::SYSTEM_UPDIR . $filePivotInverse);
        if($pivotExists === true || $pivotInverseExists === true)
        { // ManyToMany rel
            $pivotEntity = null;

            if($pivotExists)
            {
                $pivotEntity = Classes::getModelEntity($this->entity . $ucEntity);
            }
            else
            {
                if($pivotInverseExists)
                {
                    $pivotEntity = Classes::getModelEntity($ucEntity . $this->entity);
                }
            }

            if($isRemovable === true)
            {
                $this->clearPivotBeforeSave($pivotEntity, $lowEntity, $eId);
            }
            $this->savePivot($pivotEntity, $lowEntity, $entity, $eId, $rId);
        }
        else
        { // OneToOne
            $this->saveModel($ucEntity, $lowEntity, $eId, $rId);
        }
    }

    /**
     * @param string $pivotEntity
     * @param string $lowEntity
     * @param int $eId
     */
    private function clearPivotBeforeSave(string $pivotEntity, string $lowEntity, int $eId)
    {
        if($this->relsRemoved === false)
        {
            // clean up old links
            $this->getModelEntities(
                $pivotEntity,
                [$lowEntity . PhpInterface::UNDERSCORE . RamlInterface::RAML_ID, $eId]
            )->delete();
            $this->relsRemoved = true;
        }
    }

    /**
     * @param string $pivotEntity
     * @param string $lowEntity
     * @param string $entity
     * @param int $eId
     * @param int $rId
     */
    private function savePivot(string $pivotEntity, string $lowEntity, string $entity, int $eId, int $rId)
    {
        $pivot = new $pivotEntity();
        $pivot->{$entity . PhpInterface::UNDERSCORE . RamlInterface::RAML_ID} = $rId;
        $pivot->{$lowEntity . PhpInterface::UNDERSCORE . RamlInterface::RAML_ID} = $eId;
        $pivot->save();
    }

    /**
     * @param string $ucEntity
     * @param string $lowEntity
     * @param int $eId
     * @param int $rId
     */
    private function saveModel(string $ucEntity, string $lowEntity, int $eId, int $rId)
    {
        $relEntity =
            Classes::getModelEntity($ucEntity);
        $model =
            $this->getModelEntity($relEntity, $rId);
        $model->{$lowEntity . PhpInterface::UNDERSCORE . RamlInterface::RAML_ID} = $eId;
        $model->save();
    }

    /**
     *  Adds {HTTPMethod}Relations to array of route methods
     */
    private function addRelationMethods()
    {
        $ucRelations = ucfirst(JSONApiInterface::URI_METHOD_RELATIONS);
        $this->jsonApiMethods[] = JSONApiInterface::URI_METHOD_CREATE . $ucRelations;
        $this->jsonApiMethods[] = JSONApiInterface::URI_METHOD_UPDATE . $ucRelations;
        $this->jsonApiMethods[] = JSONApiInterface::URI_METHOD_DELETE . $ucRelations;
    }

    private function setDefaults()
    {
        $this->defaultPage = Config::getQueryParam(ModelsInterface::PARAM_PAGE);
        $this->defaultLimit = Config::getQueryParam(ModelsInterface::PARAM_LIMIT);
        $this->defaultSort = Config::getQueryParam(ModelsInterface::PARAM_SORT);
    }

    /**
     * Sets SqlOptions params
     * @param Request $request
     * @return SqlOptions
     */
    private function setSqlOptions(Request $request,$decode = true)
    {
        $sqlOptions = new SqlOptions();
        $page = ($request->input(ModelsInterface::PARAM_PAGE) === null) ? $this->defaultPage :
            $request->input(ModelsInterface::PARAM_PAGE);
        $limit = ($request->input(ModelsInterface::PARAM_LIMIT) === null) ? $this->defaultLimit :
            $request->input(ModelsInterface::PARAM_LIMIT);
        $sort = ($request->input(ModelsInterface::PARAM_SORT) === null) ? $this->defaultSort :
            $request->input(ModelsInterface::PARAM_SORT);
        if($decode)
        {
            $data = ($request->input(ModelsInterface::PARAM_DATA) === null) ? ModelsInterface::DEFAULT_DATA
                : Json::decode($request->input(ModelsInterface::PARAM_DATA));
            $orderBy = ($request->input(ModelsInterface::PARAM_ORDER_BY) === null) ? [RamlInterface::RAML_ID => $sort]
                : Json::decode($request->input(ModelsInterface::PARAM_ORDER_BY));
        }
        else{
            $data = ModelsInterface::DEFAULT_DATA;
            $orderBy = [RamlInterface::RAML_ID => $sort];
        }

//        $filter = ($request->input(ModelsInterface::PARAM_FILTER) === null) ? [] : Json::decode($request->input(ModelsInterface::PARAM_FILTER));
        $sqlOptions->setLimit($limit);
        $sqlOptions->setPage($page);
        $sqlOptions->setData($data);
        $sqlOptions->setOrderBy($orderBy);
//        $sqlOptions->setFilter($filter);

        return $sqlOptions;
    }

    private function setConfigOptions()
    {
        $this->configOptions = new ConfigOptions();
        $this->configOptions->setJwtIsEnabled(Config::getJwtParam(ConfigInterface::ENABLED));
        $this->configOptions->setJwtTable(Config::getJwtParam(ModelsInterface::MIGRATION_TABLE));
        if($this->configOptions->getJwtIsEnabled() === true && $this->configOptions->getJwtTable() === MigrationsHelper::getTableName($this->entity))
        {// if jwt enabled=true and tables are equal
            $this->configOptions->setIsJwtAction(true);
        }
    }

//    public function createGallery(Request $request)
//    {
//
//        $token = (new Parser())->parse((string)$request->jwt);
//
//        $id = $token->getClaim("uid");
//
//
//
//
//        $json = Json::decode($request->getContent());
//        $url = (Json::getAttributes($json)["url"]);
//
//
//
//        $jsonApiAttributes = Json::getAttributes($json);
//        foreach ($this->props as $k => $v) {
//            // request fields should match Middleware fields
//            if (isset($jsonApiAttributes[$k])) {
//                $this->model->$k = $jsonApiAttributes[$k];
//            }
//        }
//
//
//        $this->model->uid = $id;
//
//
//        putenv('GOOGLE_APPLICATION_CREDENTIALS='.storage_path('app/VidBox-Vision-92b2c7b596a3.json'));
//
//
//
//        $vision = new VisionClient([
//            'projectId' => 'vidbox-vision']);
//
//
//        $image = $vision->image($url, [
//            'LABEL_DETECTION'
//        ]);
//
//        $labels = $vision->annotate($image)->labels();
//
//        //dd($labels);
//        $tags ='';
//
//        foreach ($labels as $label)
//        {
//
//            $tags == '' ?$tags=$label->info()["description"]: $tags= $tags.','.$label->info()["description"];
//        }
//
//
//        $this->model->tags = $tags;
//        $this->model->save();
//
//
//        $this->setRelationships($json, $this->model->id);
//        $resource = Json::getResource($this->middleWare, $this->model, $this->entity);
//        Json::outputSerializedData($resource, JSONApiInterface::HTTP_RESPONSE_CODE_CREATED);
//
//
//        return response()->json([
//            "data" => ["type" => "gallery", "attributes" => ["title" => "Success", "desc" =>  $resource]]
//        ],200);
//
//    }

    public function galleryTags(Request $request)
    {


        $gallery = Gallery::find($request->id);


        $gallery->tags = 'aaaaabbbbb';

        $gallery->save();


        return 'aa';
        return response()->json([
            "data" => ["type" => "gallery", "attributes" => ["title" => "Success", "desc" =>  'aaaa']]
        ],200);
        //dd('aaaaaaaa');
        // dd($model);
        //dd($model);
        //dd($model);
//        $config = [
//            'service.enable' => true,
//            'service.file' => '/Users/alonmaor/Desktop/web/VidBox-Api/app/VidBox-Vision-92b2c7b596a3.json',
//        ];
//
//
//        $email = 'info@vidbox.media';
//
//
//
//        $client = new Client($config,$email);
//
//        $googleClient =  $client->getClient();
////dd($googleClient);

//        include_once __DIR__ . '/../../../../../vendor/autoload.php';

//        dd(__DIR__);

        putenv('GOOGLE_APPLICATION_CREDENTIALS='.storage_path('app/VidBox-Vision-92b2c7b596a3.json'));



        $vision = new VisionClient([
            'projectId' => 'vidbox-vision']);


        $image = $vision->image('http://blog.caranddriver.com/wp-content/uploads/2015/11/BMW-2-series.jpg', [
            'LABEL_DETECTION'
        ]);

        $labels = $vision->annotate($image)->labels();

//     $labels->objects->get('bucket', 'object');
//        $model->tags = 'aaaa';
//        //dd($model);
//
//        $model->save();
//        return $model;

    }
}



