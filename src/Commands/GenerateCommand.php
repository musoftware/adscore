<?php

namespace adz\core\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Process\Process;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Artisan;
 
class GenerateCommand extends Command
{
    protected $seedersPath = __DIR__.'/../../publishable/database/seeds/';
    protected $migrationsPath = __DIR__.'/../../publishable/database/migrations/';
    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'adz:types:generate';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Adz package';

    protected function getOptions()
    {
        return [
            ['with-dummy', null, InputOption::VALUE_NONE, 'Install with dummy data', null],
        ];
    }

    /**
     * Get the composer command for the environment.
     *
     * @return string
     */
    protected function findComposer()
    {
        if (file_exists(getcwd().'/composer.phar')) {
            return '"'.PHP_BINARY.'" '.getcwd().'/composer.phar';
        }

        return 'composer';
    }

    public function fire(Filesystem $filesystem)
    {   
        return $this->handle($filesystem);
    }

    public function camelize($input, $separator = '_')
    {
        return str_replace($separator, '', ucwords($input, $separator));
    }

    /**
     * Execute the console command.
     *
     * @param \Illuminate\Filesystem\Filesystem $filesystem
     *
     * @return void
     */
    public function handle(Filesystem $filesystem)
    {
      //   $this->info('');
        
       // $this->call('vendor:publish', ['--provider' => "Spatie\Activitylog\ActivitylogServiceProvider", '--tag' => "migrations"]);

        //Publish only relevant resources on install
        $tags = ['seeds'];
 
        $app = app_path() ; 

        $models = glob($app . '/Models/*.php');

        $replacements = [
            'varchar' => 'Type::string()',
            'string' => 'Type::string()',
            'id' => 'Type::ID()',
            'integer' => 'Type::int()',
            'bigint' => 'Type::int()',
            'text' => 'Type::string()',
            'timestamp' => 'Type::string()',
            'datetime' => 'Type::string()',
            'date' => 'Type::string()',
            'tinyint' => 'Type::boolean()',
            'boolean' => 'Type::boolean()',
            'float' => 'Type::float()',
            'enum' => '',
            'point' => 'Type::string()'
        ];

        $path = app_path('GraphQL');
        if(!empty($models)){
            foreach($models as $model){
                $searchValue = $searchContext = '';
                $rangeValue = $rangeContext = '';
                $whereInValue = $whereInContext = '';
                $hasAdditionalData = false; 
                $aggregatorContext  = $resContext = '' ;
                $resolve = '';
                $model = pathinfo($model);
                $modelName = $model['filename'] ;
                if(in_array($modelName,["AdzModel","AdzModelSoftDelete"]))
                    continue ;
                
                $modelObj = app("App\Models\\{$modelName}") ;    
                $fields = $modelObj->getTableColumns() ;   
                $filterRows = $additionalFields = $filterNames = [];
                $fieldsRows = [];
                foreach($fields as $field) {
                    $type =  DB::getSchemaBuilder()->getColumnType($modelObj->getTable(), $field);
                    $fieldsRows[] = "'{$field}' => [
                        'type' => $replacements[$type],
                        {$resolve}    
                    ]\n";
                }

                if($modelObj->relatable){
                    $this->appendRelations($fieldsRows, $modelObj->relatable, $filterRows, $filterNames);
                }

                if($modelObj->searchFields && !empty($modelObj->searchFields)){
                   $searchValue = "'search' => ['name' => 'search', 'type' => Type::string()],";
                   $searchContext .= 'if(isset($args["search"])){';
                   foreach($modelObj->searchFields as $key){
                  //     $searchContext .= '"$model = $model->orWhere("'.$key.'", "LIKE", "$args[\'search\']")';
                       $searchContext .= "\r\n        \$model = \$model->orWhere('{$key}', 'LIKE', '%'.\$args['search'].'%');";
                   }
                   $searchContext .= "      \r\n }";
                }

                if($modelObj->dateRange && !empty($modelObj->dateRange)){
                    foreach($modelObj->dateRange as $key){
                        $rangeValue .= "'{$this->camelize($key)}Range' => ['name' => '{$this->camelize($key)}', 'type' => Type::listOf(Type::float())],";
                        $rangeContext .= "\r\n         if(isset(\$args['{$this->camelize($key)}'])){";
                        $rangeContext .= "\r\n        \$model = \$model->dateFilter(\$args['{$this->camelize($key)}Range'][0], \$args['{$this->camelize($key)}Range'][1], '{$key}');";
                    }
                    $rangeContext .= "      \r\n }";
                 }

                 if($modelObj->whereIn && !empty($modelObj->whereIn)){
                    foreach($modelObj->whereIn as $key => $value){
                        $whereInValue .= "'{$this->camelize($key)}WhereIn' => ['name' => '{$this->camelize($key)}WhereIn', 'type' => Type::listOf(Type::$value())],";
                        $whereInContext .= "\r\n         if(isset(\$args['{$this->camelize($key)}WhereIn'])){";
                        $whereInContext .= "\r\n        \$model = \$model->whereIn('$key', \$args['{$this->camelize($key)}WhereIn']);";
                        $whereInContext .= "      \r\n }";
                    }       
                 }
                 
                 if($modelObj->aggregators && !empty($modelObj->aggregators)){
                    $aggregatorContext .= " \r\n       \$model = \$model->select('*');";
                    foreach($modelObj->aggregators as $key => $value){
                       $isRelation = isset($value['type']) && $value['type'] == "relation" ? true : false;  
                       $groupByString = implode('\',\'', array_keys($value['groupBy']));
                       $selectString = implode('\',\'', array_keys($value['select']));
                       $groupBy = $value['groupBy'] ? "\$model = \$model->groupBy('{$groupByString}');" : "";
                       $cols = $aggregates = [];
                       if(count($value['groupBy'])){
                           foreach($value['groupBy'] as $groupKey => $group){
                            if(isset($group['function'])){
                                $cols []= "'{$group['as']}' => [
                                    'type' => Type::{$group['type']}()  
                                ]\n";
                                array_push($aggregates, "{$group['function']}({$groupKey}) as {$group['as']}");
                            }
                           }
                        } 
                        if(count($value['select'])){
                            foreach($value['select'] as $select => $opts){
                                $cols []= "'{$opts['as']}' => [
                                    'type' => Type::{$opts['type']}()  
                                ]\n";
                            }
                         } 

                        $this->createTypeStub($key, 'type', $path, 'Type', 'Type', ['{$values}' => join(',', $cols)],$modelObj);
                        $aggregatesString = implode(',', $aggregates);
                        $keyColumn = "'{$key}' => [
                             'type'  => Type::listOf(GraphQL::type('{$key}Type')),
                             'resolve'=> function(\$model){
                                \r\n       \$model = \$model->select('{$selectString}')->addSelect(\\Illuminate\\Support\\Facades\\DB::raw('{$aggregatesString}'));
                                \r\n       {$groupBy}
                                \r\n       \$res = \$model->get();
                                return \$res;
                             }
                        ]";        
                        array_push($additionalFields, $keyColumn);  
                       }    
                 }
 
                $filterRowsString = implode(',', $filterRows);
                $filterNamesString = json_encode($filterNames);

                $this->createTypeStub($modelName, 'query', $path, 'Query', 'Query',[],$modelObj);
                $this->createTypeStub($modelName, 'mutation', $path, 'Mutation', 'Mutation',[],$modelObj);
                $this->createTypeStub($modelName, 'queryType', $path, 'QueryType', 'Type',['{$searchValue}' => $searchValue, '{$searchContext}' => $searchContext,'{$rangeValue}' => $rangeValue, '{$rangeContext}' => $rangeContext,'{$whereInValue}' => $whereInValue, '{$whereInContext}' => $whereInContext, '{$aggregatorContext}'=> $aggregatorContext, '{$resContext}' => $resContext], $modelObj);
                $this->createTypeStub($modelName, 'mutationType', $path, 'MutationType', 'Type',[],$modelObj);
                $this->createTypeStub($modelName, 'type', $path, 'Type', 'Type', ['{$values}' => join(',', $fieldsRows)],$modelObj);
                if(count($additionalFields)){
                    $this->createTypeStub($modelName, 'additionalDataType', $path, 'AdditionalDataType', 'Type', ['{$values}' => join(',', $additionalFields)],$modelObj);
                }
                $this->createTypeStub($modelName, 'input', $path, 'Input', 'Type', ['{$values}' => "explode(',','{$filterRowsString}')",'{$inputSpecific}'=> "{$filterNamesString}"],$modelObj);
                $this->createTypeStub($modelName, 'response', $path, 'Response', 'Type', ['{$isMulti}' => "true", '{$additionalPayload}' => $modelName.'AdditionalDataType', '{$hasAdditionalData}' => count($additionalFields) ? "true" : "false" ],$modelObj);
                $this->createTypeStub($modelName, 'response', $path, 'SingleResponse', 'Type', ['{$isMulti}' => "false",'{$additionalPayload}' => $modelName.'AdditionalDataType', '{$hasAdditionalData}' => count($additionalFields) ? "true" : "false" ],$modelObj);
            }
        }

    }

    public function appendRelations(array &$fields = [], $relatable, &$filterRows, &$filterNames){

        foreach($relatable as $type => $related){
            switch($type){
                case 'has-many':
                foreach($related as $key => $relation){
                    $name = last(explode('\\', $relation));
                    $plural = str_plural($name);
                    $fields[] = "'{$plural}' => [
                        'type' => Type::listOf(GraphQL::type('{$name}Type')),
                        'resolve' => function(\$object){
                            return \$object->$key()->get();
                        }
                        
                    ]\n";
                    $filterRows[] = $plural;
                }
                break;
                case 'belongs-to':
                foreach($related as $key => $relation){
                    $name = last(explode('\\', $relation));
                    $plural = str_plural($name);  
                    $fields[] = "'{$name}' => [
                        'type' => GraphQL::type('{$name}Type'),
                        'resolve' => function(\$object){
                            return \$object->$key()->first();
                        }
                        
                    ]\n";
                    $filterRows[] = $name;
                }
                break; 
                case 'many-to-many':
                foreach($related as $key => $relation){
                    $name = last(explode('\\', $relation));
                    $plural = str_plural($name);
                    $fields[] = "'{$plural}' => [
                        'type' => Type::listOf(GraphQL::type('{$name}Type')),
                        'resolve' => function(\$object){
                            return \$object->$key()->get();
                        }
                        
                    ]\n";
                    $filterRows[] = $plural;
                    $filterNames[$name] = ['name' => $name, 'plural' => $plural,'type' => '_mtm_'] ;
                }
                break;
            }
        }

    }


    public function createTypeStub($modelName, $stubName, $path, $stubType, $dir, $others = [], $modelObj = null) {

        $queryType = __DIR__ . "/../../stubs/{$stubName}.stub";
   
        $dismiss = array_merge(['DummyClass', '{$modelName}', '{$name}', '{$description}', '{$response}', '{$single}'], array_keys($others));
 
        $replacements = array_merge([
            $modelName. $stubType,
            $modelName,
            $modelName,
            $modelName . ' Description',
            $modelName . 'Response',
            $modelName . 'SingleResponse'
                ], array_values($others));
       
        if($stubType == "MutationType"){
            $validationContent = '';
            if($modelObj && $modelObj->rules && !empty($modelObj->rules)){
                array_push($dismiss, '{$validationSegment}');
                $validationContent .= ' $validator = Validator::make($'.$modelName.', [';
                $rules = [];
                foreach($modelObj->rules as $rule){
                    $rules[] = "'{$rule}' => 'required'\n";
                }
                $validationContent .= implode(',', $rules);
                $validationContent .= ']);';
                $validationContent .= '$errors = $validator->errors()->getMessages();';
                $validationContent .= '$_errors = [];$counter = 0;';
                $validationContent .= 'foreach($errors as $key => $value){
                    $_errors[$counter]["key"] = $key;
                    $_errors[$counter]["message"] = $value;
                    $counter++;
                }';
                $validationContent  = "{$validationContent}\nif(\$validator->fails()){\n return \$this->resolveErrors(\$_errors, null,null,400); \n}";
                array_push($replacements, $validationContent);
            }else{
                array_push($dismiss, '{$validationSegment}'); 
            }   
        }     
        
        $content = str_replace($dismiss, $replacements, file_get_contents($queryType));

        file_put_contents($path . "//{$dir}/" . $modelName . "{$stubType}.php", $content);
    }

}
  