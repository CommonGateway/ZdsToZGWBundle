<?php

namespace CommonGateway\ZdsToZGWBundle\ActionHandler;

use CommonGateway\CoreBundle\ActionHandler\ActionHandlerInterface;
use CommonGateway\ZdsToZGWBundle\Service\ZdsToZgwService;

/**
 * Haalt applications op van de componenten catalogus.
 */
class ZdsDocumentResponseHandler implements ActionHandlerInterface
{

    /**
     * @var ZdsToZgwService
     */
    private ZdsToZgwService $zdsToZgwService;


    /**
     * @param ZdsToZgwService $zdsToZgwService The ZdsToZgwService
     */
    public function __construct(ZdsToZgwService $zdsToZgwService)
    {
        $this->zdsToZgwService = $zdsToZgwService;

    }//end __construct()


    /**
     *  This function returns the required configuration as a [json-schema](https://json-schema.org/) array.
     *
     * @return array a [json-schema](https://json-schema.org/) that this  action should comply to
     */
    public function getConfiguration(): array
    {
        return [
            '$id'         => 'https://zds.nl/zds.creerdocument.handler.json',
            '$schema'     => 'https://docs.commongateway.nl/schemas/ActionHandler.schema.json',
            'title'       => 'ZdsDocumentActionHandler',
            'description' => 'This is a action to create objects from the fetched applications from the componenten catalogus.',
            'properties'  => [],
        ];

    }//end getConfiguration()


    /**
     * This function runs the application to gateway service plugin.
     *
     * @param array $data          The data from the call
     * @param array $configuration The configuration of the action
     *
     * @return array
     */
    public function run(array $data, array $configuration): array
    {
        return $this->zdsToZgwService->documentResponseHandler($data, $configuration);

    }//end run()


}//end class
