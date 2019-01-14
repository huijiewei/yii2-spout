<?php
/**
 * Created by PhpStorm.
 * User: huijiewei
 * Date: 2019-01-14
 * Time: 10:21
 */

namespace huijiewei\spout;

use Box\Spout\Common\Type;
use Box\Spout\Writer\WriterFactory;
use Box\Spout\Writer\XLSX\Writer;
use yii\base\Component;
use yii\base\InvalidArgumentException;
use yii\base\InvalidConfigException;
use yii\data\ActiveDataProvider;
use yii\db\ActiveQuery;
use yii\helpers\ArrayHelper;
use yii\helpers\FileHelper;
use yii\i18n\Formatter;
use yii\web\Response;

class Spout extends Component
{
    /* @var ActiveQuery */
    public $query;

    public $batchSize = 500;

    public $title;

    /* @var Formatter */
    public $formatter;

    public $columns = [];
    public $headerColumnUnions = [];

    public $emptyCell = '';

    public $nullDisplay = '';

    public $writerType;
    public $rowIndex;

    protected $isRendered = false;

    private $batchInfo;

    /* @var Writer */
    private $writer;

    /* @var ActiveDataProvider */
    private $dataProvider;

    public function configure($properties)
    {
        \Yii::configure($this, $properties);

        return $this;
    }

    public function init()
    {
        parent::init();

        if ($this->formatter == null) {
            $this->formatter = \Yii::$app->getFormatter();
        } elseif (is_array($this->formatter)) {
            $this->formatter = \Yii::createObject($this->formatter);
        }

        if (!$this->formatter instanceof Formatter) {
            throw new InvalidConfigException(
                'The "formatter" property must be either a Format object or a configuration array.'
            );
        }
    }

    public function send($attachmentName, $options = [])
    {
        $tmpFile = \Yii::getAlias('@runtime/temp/' .
            \Yii::$app->getSecurity()->generateRandomString(16) . '.' .
            pathinfo($attachmentName, PATHINFO_EXTENSION));

        $this->save($tmpFile);

        \Yii::$app->getResponse()->getHeaders()->set('X-Suggested-Filename', rawurlencode($attachmentName));

        \Yii::$app->getResponse()->on(Response::EVENT_AFTER_SEND, function () use ($tmpFile) {
            unlink($tmpFile);
        });

        return \Yii::$app->getResponse()->sendFile($tmpFile, $attachmentName, $options);
    }

    private function save($filename)
    {
        $filename = \Yii::getAlias($filename);
        $writerType = $this->writerType;

        if ($writerType === null) {
            $writerType = Type::XLSX;
        }

        $fileDir = pathinfo($filename, PATHINFO_DIRNAME);

        FileHelper::createDirectory($fileDir);

        $this->writer = WriterFactory::create($writerType);

        $this->writer->openToFile($filename);

        if (!$this->isRendered) {
            $this->render();
        }

        $this->writer->close();
    }

    public function render()
    {
        $this->dataProvider = new ActiveDataProvider([
            'query' => $this->query,
            'pagination' => [
                'pageSize' => $this->batchSize,
            ],
        ]);

        if ($this->isRendered) {
            $this->writer->addNewSheetAndMakeItCurrent();
        }

        $activeSheet = $this->writer->getCurrentSheet();

        if (!empty($this->title)) {
            $activeSheet->setName($this->title);
        }

        $columnsInitialized = false;
        $modelIndex = 0;

        $columns = $this->populateColumns($this->columns);

        while (($models = $this->batchModels()) !== false) {
            if (!$columnsInitialized) {
                $columnsInitialized = true;

                $this->writer->addRow(array_keys($columns));
            }

            foreach ($models as $index => $model) {
                $rowData = [];

                foreach ($columns as $name => $column) {
                    if (is_array($column)) {
                        $columnValue = $this->getColumnData($model, $column);
                    } else {
                        $columnValue = $this->getColumnData($model, ['attribute' => $column]);
                    }

                    $rowData[] = $columnValue;

                }

                $this->writer->addRow($rowData);

                $modelIndex++;
            }
        }

        $this->isRendered = true;

        return $this;
    }

    private function populateColumns($columns = [])
    {
        $result = [];

        foreach ($columns as $key => $value) {
            if (is_string($value)) {
                $valueArray = explode(':', $value);
                $result[$key] = ['attribute' => $valueArray[0]];

                if (isset($valueArray[1]) && $valueArray[1] !== null) {
                    $result[$key]['format'] = $valueArray[1];
                }

                if (isset($valueArray[2]) && $valueArray[2] !== null) {
                    $result[$key]['header'] = $valueArray[2];
                }
            } elseif (is_array($value)) {
                if (!isset($value['attribute']) && !isset($value['value'])) {
                    throw new InvalidArgumentException('Attribute or Value must be defined.');
                }

                $result[$key] = $value;
            } elseif (is_callable($value)) {
                $result[$key] = ['value' => $value];
            }
        }

        return $result;
    }

    protected function batchModels()
    {
        if ($this->batchInfo === null) {
            if ($this->query !== null && method_exists($this->query, 'batch')) {
                $this->batchInfo = [
                    'queryIterator' => $this->query->batch($this->batchSize)
                ];
            } else {
                $this->batchInfo = [
                    'pagination' => $this->dataProvider->getPagination(),
                    'page' => 0
                ];
            }
        }

        if (isset($this->batchInfo['queryIterator'])) {
            /* @var $iterator \Iterator */
            $iterator = $this->batchInfo['queryIterator'];
            $iterator->next();

            if ($iterator->valid()) {
                return $iterator->current();
            }

            $this->batchInfo = null;

            return false;
        }

        if (isset($this->batchInfo['pagination'])) {
            /* @var $pagination \yii\data\Pagination|bool */
            $pagination = $this->batchInfo['pagination'];
            $page = $this->batchInfo['page'];

            if ($pagination === false || $pagination->pageCount === 0) {
                if ($page === 0) {
                    $this->batchInfo['page']++;

                    return $this->dataProvider->getModels();
                }
            } else {
                if ($page < $pagination->pageCount) {
                    $pagination->setPage($page);
                    $this->dataProvider->prepare(true);
                    $this->batchInfo['page']++;

                    return $this->dataProvider->getModels();
                }
            }

            $this->batchInfo = null;

            return false;
        }

        return false;
    }

    private function getColumnData($model, $params = [])
    {
        $value = null;

        if (isset($params['value']) && $params['value'] !== null) {
            if (is_string($params['value'])) {
                $value = ArrayHelper::getValue($model, $params['value']);
            } else {
                $value = call_user_func($params['value'], $model, $this);
            }
        } elseif (isset($params['attribute']) && $params['attribute'] !== null) {
            $value = ArrayHelper::getValue($model, $params['attribute']);
        }

        if (isset($params['format']) && $params['format'] != null) {
            $value = $this->formatter->format($value, $params['format']);
        }

        return $value;
    }
}
