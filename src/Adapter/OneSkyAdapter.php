<?php

namespace Partnermarketing\TranslationBundle\Adapter;

use Onesky\Api\Client;
use Onesky\Api\FileFormat;
use Partnermarketing\TranslationBundle\Exception\YMLParseException;
use Symfony\Component\Yaml\Yaml as YamlParser;

class OneSkyAdapter extends TranslationAdapter
{
    private $baseTranslationsDir;
    private $targetTranslationDir;
    private $oneSkyProjectId;
    private $oneSkyApiKey;
    private $oneSkyApiSecret;
    private $baseLanguage;
    private $supportedLanguages = [];
    /** @var Client $client */
    private $client;

    public function __construct($baseTranslationsDir, $targetTranslationDir, $oneSkyProjectId, $oneSkyApiKey, $oneSkyApiSecret, $baseLanguage, $supportedLanguages)
    {
        $this->baseTranslationsDir = rtrim($baseTranslationsDir, '/');
        $this->targetTranslationDir = rtrim($targetTranslationDir, '/');
        $this->oneSkyProjectId = $oneSkyProjectId;
        $this->oneSkyApiKey = $oneSkyApiKey;
        $this->oneSkyApiSecret = $oneSkyApiSecret;
        $this->baseLanguage = $baseLanguage;
        $this->supportedLanguages = $supportedLanguages;
    }

    /**
     * @doc https://github.com/onesky/api-documentation-platform/blob/master/resources/file.md#upload---upload-a-file
     *
     * By default we always deprecate previous strings by enforcing 'is_keeping_all_strings' => false.
     *
     * @return mixed
     */
    public function pushBaseTranslations()
    {
        $files = $this->getBaseTranslationFiles();

        $client = $this->getClient();

        if(count($files) > 0) {
            foreach($files as $filePath) {
                $response = $client->files('upload', [
                    'project_id' => $this->oneSkyProjectId,
                    'file' => $filePath,
                    'file_format' => FileFormat::YML,
                    'locale' => $this->getBaseLanguage(),
                    'is_keeping_all_strings' => false
                ]);
            }
        }

        return json_decode($response, true);
    }


    /**
     * @param $locale
     * @param $sourceFileName
     *
     * @return string
     */
    public function getTranslationFile($locale, $sourceFileName)
    {
        $client = $this->getClient();
        $response = $client->translations('export', [
            'project_id' => $this->oneSkyProjectId,
            'locale' => $locale,
            'source_file_name' => $sourceFileName
        ]);

        return $response;
    }


    /**
     * Will ask adaptor for all files in all supported_languages and dump them to individual yml files.
     */
    public function dumpAllTranslationsToYamlFiles(){
        $files = $this->getBaseTranslationFiles();
        $supportedLanguages = $this->getSupportedLanguages();

        foreach($supportedLanguages as $supportedLanguage) {
            foreach ($files as $filePath) {
                $fileName            = $this->getFilenameFromFilePath( $filePath );
                $fileContent         = $this->getTranslationFile( $supportedLanguage, $fileName );
                try {
                    $yamlArray = YamlParser::parse( $fileContent );
                } catch(\Exception $e) {
                    throw new YMLParseException($e, $fileName);
                }
                $phraseCollectionKey = $this->getPhraseCollectionKeyFromFilename( $filePath );
                if ($fileContent) {
                    $this->dumpToYaml( $yamlArray, $phraseCollectionKey, $supportedLanguage );
                }
                if($supportedLanguage === $this->getBaseLanguage()) {
                    $existingContent = YamlParser::parse(file_get_contents($filePath));
                    $result = array_merge($existingContent, $yamlArray);

                    $yaml = YamlParser::dump($result, 4);
                    file_put_contents($filePath, $yaml);
                }
            }
        }

    }




    /**
    +     * This method converts a language code provided by OneSky into Symfony 2 language code format.
    +     * e.g: pt-PT -> pt_PT
    +     *
    +     * View more details about language tag here: http://en.wikipedia.org/wiki/IETF_language_tag
    +     *
    +     * @param $languageString
    +     * @return string
    +     */
    public function convertToSymfonyLanguageTag($languageString)
    {
        $languageParts = explode('-', $languageString);
        $countryCode = $languageParts[0];
        $languageTag = $countryCode;
        if(count($languageParts) > 1) {
            $languageTag .= '_'.$languageParts[1];
        }

        return $languageTag;
    }


    private function dumpToYaml($phrases, $phraseCollectionKey, $locale)
    {
        $targetFile = $this->getTargetFileFromPhraseCollectionKeyAndLocale($phraseCollectionKey, $locale);
        $targetDir = dirname($targetFile);
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0777, true);
        }

        $yaml = YamlParser::dump($phrases, 4);
        file_put_contents($targetFile, $yaml);
    }


    public function getPhraseCollectionsFromFilenames($filenames)
    {
        $collections = [];
        foreach ($filenames as $filename) {
            $key = $this->getPhraseCollectionKeyFromFilename($filename);
            $phrases = $this->getPhrasesFromFilename($filename);
            $collections[$key] = $phrases;
        }

        return $collections;
    }

    public function getPhrasesFromFilename($filename)
    {
        $parsed = YamlParser::parse(file_get_contents($filename));
        $phrases = $this->yamlArrayToDottedPhrasesArray($parsed);

        return $phrases;
    }

    private function yamlArrayToDottedPhrasesArray(array $array, $keyPrefix = null)
    {
        $result = [];

        foreach ($array as $key => $value) {
            $keyToUse = ($keyPrefix ? $keyPrefix . '.' . $key : $key);
            if (is_scalar($value)) {
                $result[$keyToUse] = ['string' => $value];
            } else {
                $result = array_merge($result, $this->yamlArrayToDottedPhrasesArray($value, $keyToUse));
            }
        }

        return $result;
    }

    public function getBaseTranslationFiles()
    {
        return self::getFilesInDirectory($this->baseTranslationsDir);
    }

    private static function getFilesInDirectory($dir)
    {
        $iterator = new \DirectoryIterator($dir);
        $files = [];

        foreach ($iterator as $file) {
            if ($file->getFilename() === '.' || $file->getFilename() === '..') {
                continue;
            }

            if (is_dir($file->getPathname())) {
                $files = array_merge($files, self::getFilesInDirectory($file->getPathname()));
            } else {
                $files[] = realpath($file->getPathname());
            }
        }

        sort($files);

        return $files;
    }

    public function getPhraseCollectionKeyFromFilename($filename)
    {
        $key = str_replace($this->baseTranslationsDir, '', $filename);
        $key = ltrim($key, '/');
        $key = preg_replace('/\.yml$/', '', $key);

        return $key;
    }

    public function getFilenameFromFilePath($filePath)
    {
        $parts = explode('/', $filePath);
        $fileName = $parts[count($parts)-1];

        return trim($fileName);
    }

    public function getTargetFileFromPhraseCollectionKeyAndLocale($phraseCollectionKey, $locale)
    {
        return $this->targetTranslationDir . '/' . $phraseCollectionKey . '.' . $locale . '.yml';
    }

    /**
     * @return Client
     */
    public function getClient(){
        if(!$this->client) {
            $this->client = $this->createClient();
        }
        return $this->client;
    }

    /**
     * @param Client $client
     */
    public function setClient(Client $client)
    {
        $this->client = $client;
    }

    /**
     * @return Client
     */
    private function createClient()
    {
        $client = new Client();
        $client->setApiKey($this->oneSkyApiKey);
        $client->setSecret($this->oneSkyApiSecret);
        return $client;
    }

    /**
     * @return string
     */
    protected function getBaseLanguage()
    {
        return $this->baseLanguage;
    }

    /**
     * @return array
     */
    public function getSupportedLanguages()
    {
        return $this->supportedLanguages;
    }

    /**
     * @param array $supportedLanguages
     */
    public function setSupportedLanguages( $supportedLanguages )
    {
        $this->supportedLanguages = $supportedLanguages;
    }

}
