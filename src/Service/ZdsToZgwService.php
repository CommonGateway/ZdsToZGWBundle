<?php

namespace CommonGateway\ZdsToZGWBundle\Service;

use App\Entity\Endpoint;
use App\Entity\Entity as Schema;
use App\Entity\File;
use App\Entity\Mapping;
use App\Entity\ObjectEntity;
use CommonGateway\CoreBundle\Service\CacheService;
use CommonGateway\CoreBundle\Service\GatewayResourceService;
use CommonGateway\CoreBundle\Service\MappingService;
use Doctrine\ORM\EntityManagerInterface;
use phpDocumentor\Reflection\Types\This;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Safe\DateTime;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Serializer\Encoder\XmlEncoder;

/**
 *  This class handles the interaction with componentencatalogus.commonground.nl.
 */
class ZdsToZgwService
{

    /**
     * @var EntityManagerInterface $entityManager
     */
    private EntityManagerInterface $entityManager;

    /**
     * @var MappingService $mappingService
     */
    private MappingService $mappingService;

    /**
     * @var CacheService $cacheService
     */
    private CacheService $cacheService;

    /**
     * @var LoggerInterface $pluginLogger
     */
    private LoggerInterface $pluginLogger;

    /**
     * @var GatewayResourceService $resourceService
     */
    private GatewayResourceService $resourceService;

    private ParameterBagInterface $parameterBag;

    /**
     * @var array $configuration
     */
    private array $configuration;

    /**
     * @var array $data
     */
    private array $data;


    /**
     * @param EntityManagerInterface $entityManager   The Entity Manager
     * @param MappingService         $mappingService  The MappingService
     * @param CacheService           $cacheService    The CacheService
     * @param LoggerInterface        $pluginLogger    The Logger Interface
     * @param GatewayResourceService $resourceService The Gateway Resource Service
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        MappingService $mappingService,
        CacheService $cacheService,
        LoggerInterface $pluginLogger,
        GatewayResourceService $resourceService,
        ParameterBagInterface $parameterBag
    ) {
        $this->entityManager   = $entityManager;
        $this->mappingService  = $mappingService;
        $this->cacheService    = $cacheService;
        $this->logger          = $pluginLogger;
        $this->resourceService = $resourceService;
        $this->parameterBag = $parameterBag;

        $this->data          = [];
        $this->configuration = [];

    }//end __construct()


    /**
     * Creates a response based on content.
     *
     * @param array $content The content to incorporate in the response
     * @param int   $status  The status code of the response
     *
     * @return Response
     */
    public function createResponse(array $content, int $status): Response
    {
        $this->logger->debug('Creating XML response');
        $xmlEncoder    = new XmlEncoder(['xml_root_node_name' => 'SOAP-ENV:Envelope']);
        $contentString = $xmlEncoder->encode($content, 'xml', ['xml_encoding' => 'utf-8', 'remove_empty_tags' => true]);

        return new Response($contentString, $status);

    }//end createResponse()


    /**
     * Handles incoming creeerZaakIdentificatie messages, creates a case with incoming reference as identificatie field.
     *
     * @param array $data   The inbound data from the request
     * @param array $config The configuration for the handler
     *
     * @return array The updated handler response
     */
    public function zaakIdentificatieActionHandler(array $data, array $config): array
    {
        $this->logger->info('Handling Create Case Identification');
        $this->configuration = $config;
        $this->data          = $data;

        $schema     = $this->resourceService->getSchema('https://vng.opencatalogi.nl/schemas/zrc.zaak.schema.json', 'common-gateway/zds-to-zgw-bundle');
        $mapping    = $this->resourceService->getMapping('https://zds.nl/mapping/zds.zdsZaakIdToZgwZaak.mapping.json', 'common-gateway/zds-to-zgw-bundle');
        $mappingOut = $this->resourceService->getMapping('https://zds.nl/mapping/zds.zgwZaakToDu02.mapping.json', 'common-gateway/zds-to-zgw-bundle');
        if ($schema === null || $mapping === null || $mappingOut === null) {
            return $this->data;
        }

        $zaakArray = $this->mappingService->mapping($mapping, $this->data['body']);
        $zaken     = $this->cacheService->searchObjects(null, ['identificatie' => $zaakArray['identificatie']], [$schema->getId()->toString()])['results'];
        if (empty($zaken) === true) {
            $this->logger->debug('Creating new case with identifier'.$zaakArray['identificatie']);
            $zaak = new ObjectEntity($schema);
            $zaak->hydrate($zaakArray);

            $this->entityManager->persist($zaak);
            $this->entityManager->flush();

            $this->logger->info('Created case with identifier '.$zaakArray['identificatie']);
            $this->data['response'] = $this->createResponse($this->mappingService->mapping($mappingOut, $zaak->toArray()), 200);
        }

        if (empty($zaken) === false) {
            $this->logger->warning('Case with identifier '.$zaakArray['identificatie'].' found, returning bad request error');
            $this->data['response'] = $this->createResponse(['Error' => 'The case with id '.$zaakArray['identificatie'].' already exists'], 400);
        }//end if

        return $this->data;

    }//end zaakIdentificatieActionHandler()


    /**
     * Handles incoming creeerDocumentIdentificatie messages, creates a document with incoming reference as identificatie field.
     *
     * @param array $data   The inbound data from the request
     * @param array $config The configuration for the handler
     *
     * @return array The updated handler response
     */
    public function documentIdentificatieActionHandler(array $data, array $config): array
    {
        $this->logger->info('Handling Create Document Identification');
        $this->configuration = $config;
        $this->data          = $data;

        $schema     = $this->resourceService->getSchema('https://vng.opencatalogi.nl/schemas/drc.enkelvoudigInformatieObject.schema.json', 'common-gateway/zds-to-zgw-bundle');
        $mapping    = $this->resourceService->getMapping('https://zds.nl/mapping/zds.zdsDocumentIdToZgwDocument.mapping.json', 'common-gateway/zds-to-zgw-bundle');
        $mappingOut = $this->resourceService->getMapping('https://zds.nl/mapping/zds.zgwDocumentToDu02.mapping.json', 'common-gateway/zds-to-zgw-bundle');
        if ($schema === null || $mapping === null || $mappingOut === null) {
            return $this->data;
        }

        $documentArray = $this->mappingService->mapping($mapping, $this->data['body']);
        $documents     = $this->cacheService->searchObjects(null, ['identificatie' => $documentArray['identificatie']], [$schema->getId()->toString()])['results'];
        if (empty($documents) === true) {
            $this->logger->debug('Creating new document for identification'.$documentArray['identificatie']);
            $document = new ObjectEntity($schema);
            $document->hydrate($documentArray);

            $this->entityManager->persist($document);
            $this->entityManager->flush();

            $this->logger->info('Created document with identifier '.$documentArray['identificatie']);
            $this->data['response'] = $this->createResponse($this->mappingService->mapping($mappingOut, $document->toArray()), 200);
        }

        if (empty($documents) === false) {
            $this->logger->warning('Document with identifier '.$documentArray['identificatie'].' found, returning bad request error');
            $this->data['response'] = $this->createResponse(['Error' => 'The document with id '.$documentArray['identificatie'].' already exists'], 400);
        }//end if

        return $this->data;

    }//end documentIdentificatieActionHandler()


    /**
     * Connects Eigenschappen to ZaakType if eigenschap does not exist yet, or connect existing Eigenschap to ZaakEigenschap.
     *
     * @param array        $zaakArray The mapped zaak
     * @param ObjectEntity $zaakType  The zaakType to connect
     *
     * @return array The updated zaakArray with eigenschappen
     */
    public function connectEigenschappen(array $zaakArray, ObjectEntity $zaakType): array
    {
        $this->logger->info('Trying to connect case type properties to existing properties');

        $schema = $this->resourceService->getSchema('https://vng.opencatalogi.nl/schemas/ztc.eigenschap.schema.json', 'common-gateway/zds-to-zgw-bundle');
        if ($schema === null) {
            return $zaakArray;
        }

        $eigenschapObjects = $zaakType->getValue('eigenschappen');
        foreach ($zaakArray['eigenschappen'] as $key => $eigenschap) {
            $eigenschappen = $this->cacheService->searchObjects(null, ['naam' => $eigenschap['eigenschap']['naam'], 'zaaktype' => $zaakType->getSelf()], [$schema->getId()->toString()])['results'];

            if (empty($eigenschappen) === false) {
                $this->logger->debug('Property has been found, connecting to property');

                $eigenschapObject    = $this->entityManager->find('App:ObjectEntity', $eigenschappen[0]['_self']['id']);
                $eigenschapObjects[] = $zaakArray['eigenschappen'][$key]['eigenschap'] = $eigenschapObject;
            }

            if (empty($eigenschappen) === true) {
                $this->logger->debug('No existing property found, creating new property');

                $eigenschapObject                     = new ObjectEntity($schema);
                $eigenschap['eigenschap']['zaaktype'] = $zaakType->getSelf();
                $eigenschapObject->hydrate($eigenschap['eigenschap']);

                $this->entityManager->persist($eigenschapObject);
                $this->entityManager->flush();
                $eigenschapObjects[] = $zaakArray['eigenschappen'][$key]['eigenschap'] = $eigenschapObject;
            }//end if
        }//end foreach

        $zaakType->hydrate(['eigenschappen' => $eigenschapObjects]);

        $this->logger->info('Connected case properties to case type properties');

        return $zaakArray;

    }//end connectEigenschappen()


    /**
     * Connects RoleTypes to ZaakType if RoleType does not exist yet, or connect existing RoleType to Role.
     *
     * @param array        $zaakArray The mapped zaak
     * @param ObjectEntity $zaakType  The zaakType to connect
     *
     * @return array The updated zaakArray with roltypen
     */
    public function connectRolTypes(array $zaakArray, ObjectEntity $zaakType): array
    {
        $this->logger->info('Trying to connect roles to existing role types');
        $schema = $this->resourceService->getSchema('https://vng.opencatalogi.nl/schemas/ztc.rolType.schema.json', 'common-gateway/zds-to-zgw-bundle');
        if ($schema === null) {
            return $zaakArray;
        }

        $rolTypeObjects = $zaakType->getValue('roltypen');
        foreach ($zaakArray['rollen'] as $key => $role) {
            $rollen = $this->cacheService->searchObjects(null, ['omschrijvingGeneriek' => $role['roltype']['omschrijvingGeneriek'], 'zaaktype' => $zaakType->getSelf()], [$schema->getId()->toString()])['results'];

            if (empty($rollen) === false) {
                $this->logger->debug('Role type has been found, connecting to existing role type');

                $rolType          = $this->entityManager->find('App:ObjectEntity', $rollen[0]['_self']['id']);
                $rolTypeObjects[] = $zaakArray['rollen'][$key]['roltype'] = $rolType;
            }

            if (empty($rollen) === true) {
                $this->logger->debug('No existing role type has been found, creating new role type');
                $rolType                     = new ObjectEntity($schema);
                $role['roltype']['zaaktype'] = $zaakType->getSelf();
                $rolType->hydrate($role['roltype']);

                $this->entityManager->persist($rolType);
                $this->entityManager->flush();

                $rolTypeObjects[] = $zaakArray['rollen'][$key]['roltype'] = $rolType;
            }//end if
        }//end foreach

        $zaakType->hydrate(['roltypen' => $rolTypeObjects]);

        $this->logger->info('Connected roles to role types');

        return $zaakArray;

    }//end connectRolTypes()


    /**
     * Creates ZaakType if no ZaakType exists, connect existing ZaakType if ZaakType with identifier exists.
     *
     * @param array $zaakArray The mapped case
     *
     * @return array|null The updated zaakArray with zaaktype
     */
    public function convertZaakType(array $zaakArray): ?array
    {
        $this->logger->debug('Trying to connect case to existing case type');

        $schema = $this->resourceService->getSchema('https://vng.opencatalogi.nl/schemas/ztc.zaakType.schema.json', 'common-gateway/zds-to-zgw-bundle');
        if ($schema === null) {
            return null;
        }

        $zaaktypes = $this->cacheService->searchObjects(null, ['identificatie' => $zaakArray['zaaktype']['identificatie']], [$schema->getId()->toString()])['results'];

        if (empty($zaaktypes) === false && count($zaaktypes) > 0) {
            $this->logger->debug('Case type found, connecting case to case type');

            $zaaktype = $this->entityManager->find('App:ObjectEntity', $zaaktypes[0]['_self']['id']);
        }

        // Search levert geen resultaat op dus maak een nieuw object aan.
        // Of: Search levert resultaat op en het object wordt niet gevonden maak een nieuw object aan.
        if (empty($zaaktypes) === true) {
            $this->logger->debug('No existing case type found, creating new case type');

            $zaaktype = new ObjectEntity($schema);
            $zaaktype->hydrate($zaakArray['zaaktype']);

            $this->entityManager->persist($zaaktype);
            $this->entityManager->flush();
        }

        if (isset($zaaktype) === false || isset($zaaktype) === true && $zaaktype === null) {
            return null;
        }

        $zaakArray['zaaktype'] = $zaaktype;

        $this->logger->info('Case connected to case type with identification'.$zaaktype->toArray()['identificatie']);

        // TODO: connect catalogus.
        $zaakArray = $this->connectEigenschappen($zaakArray, $zaaktype);
        $zaakArray = $this->connectRolTypes($zaakArray, $zaaktype);

        return $zaakArray;

    }//end convertZaakType()


    /**
     * Receives a case and maps it to a ZGW case.
     *
     * @param array $data   The inbound data for the case
     * @param array $config The configuration for the action
     *
     * @return array The updated handler response
     */
    public function zaakActionHandler(array $data, array $config): array
    {
        $this->logger->info('Populate case');
        $this->configuration = $config;
        $this->data          = $data;

        $schema     = $this->resourceService->getSchema('https://vng.opencatalogi.nl/schemas/zrc.zaak.schema.json', 'common-gateway/zds-to-zgw-bundle');
        $mapping    = $this->resourceService->getMapping('https://zds.nl/mapping/zds.zdsZaakToZgwZaak.mapping.json', 'common-gateway/zds-to-zgw-bundle');
        $mappingOut = $this->resourceService->getMapping('https://zds.nl/mapping/zds.zgwZaakToBv03.mapping.json', 'common-gateway/zds-to-zgw-bundle');
        if ($schema === null || $mapping === null || $mappingOut === null) {
            return $this->data;
        }

        $zaakArray = $this->mappingService->mapping($mapping, $this->data['body']);

        $zaakArray = $this->convertZaakType($zaakArray);
        if ($zaakArray === null) {
            $this->logger->warning('No case was found for identifier'.$zaakArray['identificatie']);

            $this->data['response'] = $this->createResponse(['Error' => 'The case with id '.$zaakArray['identificatie'].' does not exist'], 400);

            return $this->data;
        }

        $zaken = $this->cacheService->searchObjects(null, ['identificatie' => $zaakArray['identificatie']], [$schema->getId()->toString()])['results'];

        // Create response with created zaaktype if the zaak is not empty and if there is one result.
        if (empty($zaken) === false && count($zaken) === 1) {
            $this->logger->debug('Populating case with identification '.$zaakArray['identificatie']);

            $zaak = $this->entityManager->find('App:ObjectEntity', $zaken[0]['_self']['id']);
            $zaak->hydrate($zaakArray);

            $this->entityManager->persist($zaak);
            $this->entityManager->flush();

            $this->logger->info('Populated case with identification'.$zaakArray['identificatie']);

            $this->data['object']   = $zaak->toArray();
            $this->data['response'] = $this->createResponse($this->mappingService->mapping($mappingOut, $zaak->toArray()), 200);
        }

        // Create error response if the zaken is not empty and if there is more then one result.
        if (empty($zaken) === false && count($zaken) > 1) {
            $this->logger->warning('More than one case was found for identifier'.$zaakArray['identificatie']);

            $this->data['response'] = $this->createResponse(['Error' => 'More than one case exists with id '.$zaakArray['identificatie']], 400);
        }

        // Create error response if the zaken is empty.
        if (empty($zaken) === true) {
            $this->logger->warning('No case was found for identifier'.$zaakArray['identificatie']);

            $this->data['response'] = $this->createResponse(['Error' => 'The case with id '.$zaakArray['identificatie'].' does not exist'], 400);
        }//end if

        return $this->data;

    }//end zaakActionHandler()


    /**
     * Creates an informatieobjecttype with the given data.
     *
     * @param string $description The description of the informatieobjecttype.
     * @param Schema $schema      The ztc informatieobjecttype schema.
     *
     * @return ObjectEntity The created informatieobjecttype
     */
    public function createInformatieobjecttype(string $description, Schema $schema): ObjectEntity
    {
        $date = new DateTime('now');
        // Omschrijving hebben we al.
        $dataArray = [
            'catalogus'                   => null,
            // From zaaktype,
            'omschrijving'                => $description,
            'vertrouwelijkheidaanduiding' => 'zaakvertrouwelijk',
            // TODO: Uitvragen wat dit moet zijn.
            'beginGeldigheid'             => $date->format('Y-m-d'),
            // TODO: Uitvragen wat dit moet zijn.
        ];

        // zaaktype moet een catalogus hebben.
        $informatieobjecttype = new ObjectEntity($schema);
        $informatieobjecttype->hydrate($dataArray);

        $this->entityManager->persist($informatieobjecttype);
        $this->entityManager->flush();

        return $informatieobjecttype;

    }//end createInformatieobjecttype()


    /**
     * Get the zaaktype object from the zaak.
     *
     * @param ObjectEntity $zaak The zaak object
     *
     * @return ObjectEntity|null The zaaktype object
     */
    public function getZaaktypeObject(ObjectEntity $zaak): ?ObjectEntity
    {
        $zaakArray = $zaak->toArray();
        $zaaktype  = $zaak->getValue('zaaktype');

        // If the zaaktype is not set to the zaak object, but is set to the zaak array and is a uuid find the zaaktype object.
        if ($zaaktype === false && $zaakArray['zaaktype'] !== null && is_string($zaakArray['zaaktype']) === true && Uuid::isValid($zaakArray['zaaktype']) === true) {
            $zaaktype = $this->entityManager->find('App:ObjectEntity', $zaakArray['zaaktype']);

            // Set the zaaktype object to the zaakArray.
            $zaakArray['zaaktype'] = $zaaktype;
        }

        // If the zaaktype is not set to the zaak object, but is set as array to the zaak array and has a _self.id get the zaaktype object.
        if ($zaaktype === false && $zaakArray['zaaktype'] !== null && is_array($zaakArray['zaaktype']) === true && key_exists('_self', $zaakArray['zaaktype']) === true && key_exists('id', $zaakArray['zaaktype']['_self']) === true) {
            $zaaktype = $this->entityManager->find('App:ObjectEntity', $zaakArray['zaaktype']['_self']['id']);
        }

        // If the zaaktype is not false and is null or the zaaktype is false and the zaak array zaaktype is null create error response.
        if ($zaaktype !== false && $zaaktype === null || $zaaktype === false && $zaakArray['zaaktype'] === null) {
            $this->logger->warning('There is no zaaktype set to the zaak with identification: '.$zaakArray['identificatie']);

            $this->data['response'] = $this->createResponse(['Error' => 'There is no zaaktype set to the zaak with identification: '.$zaakArray['identificatie']], 400);

            return null;
        }

        return $zaaktype;

    }//end getZaaktypeObject()


    /**
     * Creates informatieobjecttype if no informatieobjecttype exists, connect existing informatieobjecttype if informatieobjecttype with description exists.
     * Connects the informatieobjecttype to the zaaktype.
     *
     * @param array        $zaakDocumentArray The mapped document
     * @param ObjectEntity $zaak              The zaak object
     *
     * @return array|null The updated zaakDocumentArray with informatieobjecttype.
     */
    public function convertInformatieobjecttype(array $zaakDocumentArray, ObjectEntity $zaak): ?array
    {
        $this->logger->debug('Trying to connect case to existing case type');

        $zaaktype = $this->getZaaktypeObject($zaak);
        if ($zaaktype === null) {
            return null;
        }

        $description = $zaakDocumentArray['informatieobject']['informatieobjecttype']['omschrijving'];
        unset($zaakDocumentArray['informatieobject']['informatieobjecttype']['omschrijving']);

        $schema = $this->resourceService->getSchema('https://vng.opencatalogi.nl/schemas/ztc.informatieObjectType.schema.json', 'common-gateway/zds-to-zgw-bundle');
        if ($schema === null) {
            return null;
        }

        $nformatieobjecttypes = $this->cacheService->searchObjects(null, ['omschrijving' => $description], [$schema->getId()->toString()])['results'];

        $informatieobjecttypen = [];
        // Search levert resultaat op dus haal het bestaande object op.
        if (empty($nformatieobjecttypes) === false) {
            $this->logger->debug('Information object type found, connecting case type and information object to information object type');
            $informatieobjecttypen[] = $informatieobjecttype = $this->entityManager->find('App:ObjectEntity', $nformatieobjecttypes[0]['_self']['id']);
        }

        // Search levert resultaat op dus maar het object wordt niet gevonden maak een nieuw object aan.
        if (empty($nformatieobjecttypes) === false && isset($informatieobjecttype) === false) {
            $informatieobjecttypen[] = $informatieobjecttype = $this->createInformatieobjecttype($description, $schema);
        }

        // Search levert geen resultaat op dus maak een nieuw object aan.
        if (empty($nformatieobjecttypes) === true) {
            $this->logger->debug('No existing information object type found, creating new information object type');

            $informatieobjecttypen[] = $informatieobjecttype = $this->createInformatieobjecttype($description, $schema);
        }

        if (isset($informatieobjecttype) === false || isset($informatieobjecttype) === true && $informatieobjecttype === null) {
            return null;
        }

        // Set the informatieobjecttype to the zaaktype object.
        $zaaktype->hydrate(['informatieobjecttypen' => $informatieobjecttypen]);
        $this->entityManager->persist($zaaktype);
        $this->entityManager->flush();

        $zaakDocumentArray['informatieobject']['informatieobjecttype'] = $informatieobjecttype;

        return $zaakDocumentArray;

    }//end convertInformatieobjecttype()


    /**
     * Receives a zaak with the identification of the document.
     *
     * @param array $zaakDocumentArray The mapped zaak document array
     *
     * @return ObjectEntity|null The zaak object
     */
    public function getCaseFromDocument(array $zaakDocumentArray): ?ObjectEntity
    {
        $schema = $this->resourceService->getSchema('https://vng.opencatalogi.nl/schemas/zrc.zaak.schema.json', 'common-gateway/zds-to-zgw-bundle');
        if ($schema === null) {
            return null;
        }

        $zaken = $this->cacheService->searchObjects(null, ['identificatie' => $zaakDocumentArray['zaak']], [$schema->getId()->toString()])['results'];

        // Get the zaak id the zaken array is not empty.
        if (empty($zaken) === false && count($zaken) === 1) {
            $zaak = $this->entityManager->find('App:ObjectEntity', $zaken[0]['_self']['id']);
        }

        // Create error response.
        if (isset($zaak) === false || isset($zaak) === true && $zaak === null) {
            $this->logger->warning('The case object with id '.$zaakDocumentArray['zaak'].' does not exist');
            $this->data['response'] = $this->createResponse(['Error' => 'The case object with id '.$zaakDocumentArray['zaak'].' does not exist'], 400);

            return null;
        }

        // Create error response.
        if (empty($zaken) === false && count($zaken) > 1) {
            $this->logger->warning('More than one case exists with id '.$zaakDocumentArray['zaak']);
            $this->data['response'] = $this->createResponse(['Error' => 'More than one case exists with id '.$zaakDocumentArray['zaak']], 400);

            return null;
        }

        // Create error response.
        if (empty($zaken) === true) {
            $this->logger->warning('The case with id '.$zaakDocumentArray['zaak'].' does not exist');
            $this->data['response'] = $this->createResponse(['Error' => 'The case with id '.$zaakDocumentArray['zaak'].' does not exist'], 400);

            return null;
        }

        return $zaak;

    }//end getCaseFromDocument()



    /**
     * Generates a download endpoint from the id of an 'Enkelvoudig Informatie Object' and the endpoint for downloads.
     *
     * @param string   $id               The id of the Enkelvoudig Informatie Object.
     * @param Endpoint $downloadEndpoint The endpoint for downloads.
     *
     * @return string The endpoint to download the document from.
     */
    private function generateDownloadEndpoint(string $id, Endpoint $downloadEndpoint): string
    {
        // Unset the last / from the app_url.
        $baseUrl = rtrim($this->parameterBag->get('app_url'), '/');

        $pathArray = $downloadEndpoint->getPath();
        foreach ($pathArray as $key => $value) {
            if ($value == 'id' || $value == '[id]' || $value == '{id}') {
                $pathArray[$key] = $id;
            }
        }

        return $baseUrl.'/api/'.implode('/', $pathArray);

    }//end generateDownloadEndpoint()


    /**
     * Creates or updates a file associated with a given ObjectEntity instance.
     *
     * This method handles the logic for creating or updating a file based on
     * provided data. If an existing file is associated with the ObjectEntity,
     * it updates the file's properties; otherwise, it creates a new file.
     * It also sets the response data based on the method used (POST or other)
     * and if the `$setResponse` parameter is set to `true`.
     *
     * @param ObjectEntity $objectEntity The object entity associated with the file.
     * @param array        $data         Data associated with the file such as title, format, and content.
     *
     * @return void
     */
    public function createFile(ObjectEntity $objectEntity, array $data): File
    {
        if ($data['versie'] === null) {
            $objectEntity->hydrate(['versie' => 1]);
        }

        if ($data['versie'] !== null) {
            $objectEntity->hydrate(['versie' => ++$data['versie']]);
        }

        $file = new File();
        $file->setBase64('');
        $file->setMimeType(($data['formaat'] ?? 'application/pdf'));
        $file->setName($data['titel']);
        $file->setExtension('');
        $file->setSize(0);

        return $file;

    }//end createFile()


    /**
     * Creates or updates a file associated with a given ObjectEntity instance.
     *
     * This method handles the logic for creating or updating a file based on
     * provided data. If an existing file is associated with the ObjectEntity,
     * it updates the file's properties; otherwise, it creates a new file.
     * It also sets the response data based on the method used (POST or other)
     * and if the `$setResponse` parameter is set to `true`.
     *
     * @param ObjectEntity $objectEntity     The object entity associated with the file.
     * @param array        $data             Data associated with the file such as title, format, and content.
     * @param Endpoint     $downloadEndpoint Endpoint to use for downloading the file.
     * @param bool         $setResponse      Determines if a response should be set, default is `true`.
     *
     * @return void
     */
    public function createOrUpdateFile(ObjectEntity $objectEntity, array $data, Endpoint $downloadEndpoint, bool $setResponse=true): void
    {
        if ($objectEntity->getValueObject('inhoud') !== false && $objectEntity->getValueObject('inhoud')->getFiles()->count() > 0) {
            // Get the file from the inhoud object.
            $file = $objectEntity->getValueObject('inhoud')->getFiles()->first();
        }

        if ($objectEntity->getValueObject('inhoud') !== false && $objectEntity->getValueObject('inhoud')->getFiles()->count() === 0) {
            // Create the file with the data.
            $file = $this->createFile($objectEntity, $data);
        }

        if ($data['inhoud'] !== null && $data['inhoud'] !== '' && filter_var($data['inhoud'], FILTER_VALIDATE_URL) === false) {
            $file->setSize(mb_strlen(base64_decode($data['inhoud'])));
            $file->setBase64($data['inhoud']);
        }

        $file->setValue($objectEntity->getValueObject('inhoud'));
        $this->entityManager->persist($file);
        $objectEntity->getValueObject('inhoud')->addFile($file)->setStringValue($this->generateDownloadEndpoint($objectEntity->getId()->toString(), $downloadEndpoint));
        $this->entityManager->persist($objectEntity);
        $this->entityManager->flush();

        if ($setResponse === true) {
            $this->data['response'] = new Response(
                \Safe\json_encode($objectEntity->toArray(['embedded' => true])),
                $this->data['method'] === 'POST' ? 201 : 200,
                ['content-type' => 'application/json']
            );
        }

    }//end createOrUpdateFile()


    /**
     * Creates an enkelvoudiginformatieobject with an informatieobjecttype.
     * Creates a zaakinformatieobject with the zaak and created enkelvoudiginformatieobject
     *
     * @param array        $zaakDocumentArray The mapped zaak document array
     * @param string       $documentId        The id of the enkelvoudiginformatieobject document.
     * @param ObjectEntity $zaak              The zaak object.
     *
     * @return ObjectEntity|null The created zaakinformatieobject
     */
    public function createDocuments(array $zaakDocumentArray, string $documentId, ObjectEntity $zaak): ?ObjectEntity
    {
        $schema = $this->resourceService->getSchema('https://vng.opencatalogi.nl/schemas/zrc.zaakInformatieObject.schema.json', 'common-gateway/zds-to-zgw-bundle');
        if ($schema === null) {
            return null;
        }

        // Enkelvoudiginformatieobject
        $informatieobject = $this->entityManager->find('App:ObjectEntity', $documentId);
        if ($informatieobject === null) {
            $this->logger->warning('The enkelvoudig informatie object with id '.$documentId.' does not exist');
            $this->data['response'] = $this->createResponse(['Error' => 'The enkelvoudig informatie object with id '.$documentId.' does not exist'], 400);

            return null;
        }

        $informatieobject->hydrate($zaakDocumentArray['informatieobject']);

        if(in_array($zaak->getValue('zaaktype')->getValue('identificatie'), ['B333', 'B334'])) {
            $endpoint = $this->resourceService->getEndpoint('https://vng.opencatalogi.nl/endpoints/drc.downloadEnkelvoudigInformatieObject.endpoint.json', 'common-gateway/zds-to-zgw-bundle');
            $this->createOrUpdateFile($informatieobject, $zaakDocumentArray['informatieobject'], $endpoint, false);
        }

        $this->entityManager->persist($informatieobject);
        $this->entityManager->flush();

        $zaakInformatieObject = new ObjectEntity($schema);
        // TODO: Set status.
        $zaakInformatieObject->hydrate(['zaak' => $zaak, 'informatieobject' => $informatieobject]);

        $this->entityManager->persist($zaakInformatieObject);
        $this->entityManager->flush();

        return $zaakInformatieObject;

    }//end createDocuments()


    /**
     * Receives a document and maps it to a ZGW zaakinformatieobject.
     *
     * @param array $data   The inbound data for the case
     * @param array $config The configuration for the action
     *
     * @return array The updated handler response
     */
    public function documentActionHandler(array $data, array $config): array
    {
        $this->logger->info('Populating document');
        $this->configuration = $config;
        $this->data          = $data;

        $schema     = $this->resourceService->getSchema('https://vng.opencatalogi.nl/schemas/drc.enkelvoudigInformatieObject.schema.json', 'common-gateway/zds-to-zgw-bundle');
        $mapping    = $this->resourceService->getMapping('https://zds.nl/mapping/zds.zdsDocumentToZgwDocument.mapping.json', 'common-gateway/zds-to-zgw-bundle');
        $mappingOut = $this->resourceService->getMapping('https://zds.nl/mapping/zds.zgwDocumentToBv03.mapping.json', 'common-gateway/zds-to-zgw-bundle');
        if ($schema === null || $mapping === null || $mappingOut === null) {
            return $this->data;
        }

        // Map the body from the request.
        $zaakDocumentArray = $this->mappingService->mapping($mapping, $this->data['body']);

        // Get the case from the zaakDocumentArray.
        // If the case cannot be found the return value is null and the error response is set.
        $zaak = $this->getCaseFromDocument($zaakDocumentArray);
        if ($zaak === null) {
            return $this->data;
        }

        // Get the informatieobjecttype (and create if it does not exists) and set it to the zaakDocumentArray.
        $zaakDocumentArray = $this->convertInformatieobjecttype($zaakDocumentArray, $zaak);

        // Search enkelvoudiginformatieobject objects with the identificatie in informatieobject.identificatie.
        $documenten = $this->cacheService->searchObjects(null, ['identificatie' => $zaakDocumentArray['informatieobject']['identificatie']], [$schema->getId()->toString()])['results'];

        // Create response with created zaakinformatieobject if the document is not empty and if there is one result.
        if (empty($documenten) === false && count($documenten) === 1) {
            $this->logger->debug('Populating document with identification'.$zaakDocumentArray['informatieobject']['identificatie']);

            // Create an enkelvoudiginformatieobject with informatieobjecttype.
            // And create a zaakinformatieobject with zaak and enkelvoudiginformatieobject.
            $zaakInformatieObject = $this->createDocuments($zaakDocumentArray, $documenten[0]['_self']['id'], $zaak);
            if ($zaakInformatieObject === null) {
                return $this->data;
            }

            $this->logger->info('Populated document with identification'.$zaakDocumentArray['informatieobject']['identificatie']);

            // Set the zaakinformatieobject to the data.documents array.
            $this->data['documents'][] = $zaakInformatieObject->toArray();

            $response = $this->createResponse($this->mappingService->mapping($mappingOut, $zaakInformatieObject->toArray()), 200);
            // Set the response with the mapped zaakinformatieobject to Bv03.
            $this->data['response'] = $this->createResponse($this->mappingService->mapping($mappingOut, $zaakInformatieObject->toArray()), 200);
        }

        // Create error response if the document is not empty and if there is more then one result.
        if (empty($documenten) === false && count($documenten) > 1) {
            $this->logger->warning('More than one document exists with id '.$zaakDocumentArray['informatieobject']['identificatie']);
            $this->data['response'] = $this->createResponse(['Error' => 'More than one document exists with id '.$zaakDocumentArray['informatieobject']['identificatie']], 400);
        }

        // Create error response if the document is empty.
        if (empty($documenten) === true) {
            $this->logger->warning('The document with id '.$zaakDocumentArray['informatieobject']['identificatie'].' does not exist');
            $this->data['response'] = $this->createResponse(['Error' => 'The document with id '.$zaakDocumentArray['informatieobject']['identificatie'].' does not exist'], 400);
        }

        return $this->data;

    }//end documentActionHandler()

    /**
     * Generates a response to document calls
     *
     * @param array $data The data received from the event.
     * @param array $configuration The configuration for the action.
     *
     * @return array The resulting data, including a response.
     *
     * @throws \Twig\Error\LoaderError
     * @throws \Twig\Error\SyntaxError
     */
    public function documentResponseHandler(array $data, array $configuration): array
    {
        $mappingOut = $this->resourceService->getMapping('https://zds.nl/mapping/zds.zgwDocumentToBv03.mapping.json', 'common-gateway/zds-to-zgw-bundle');

        $data['response'] = $this->createResponse($this->mappingService->mapping($mappingOut, $data['body']), 200);
        return $data;

    }//end documentResponseHandler()


}//end class
