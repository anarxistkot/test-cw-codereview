<?php

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class Controller extends AbstractController
{
    /**
     * @var Service
     */
    private $service;

    public function sendTime(Request $request): JsonResponse
    {
        $requestDatetime = $request->get('datetime', null);
        $requestDatetime = new \DateTime($requestDatetime);

        $regNum = $request->get('regNum', null);

        if (null === $regNum) {
            throw new BadRequestHttpException('Не указан регистрационный номер процедуры');
        }

        $type = $request->get('type', null);

        if (null === $type) {
            throw new BadRequestHttpException('Необходимо указать тип пакета');
        }

        $isChangeTime = 'change' === $type;
        $isBiddingTime = 'bidding' === $type;

        if (!($isChangeTime || $isBiddingTime)) {
            throw new BadRequestHttpException('Необходимо указать корректный тип пакета');
        }

        $result = $this->service->setTime($requestDatetime, $regNum, $isChangeTime);

        return $this->json($result);
    }
}

class Service
{
    /**
     * @var ProcedureManagerInterface
     */
    private $procedureManager;

    /**
     * @var LotManagerInterface
     */
    private $lotManager;

    /**
     * @var EntityManagerInterface
     */
    private $em;

    /**
     * @var AuctionServiceInterface
     */
    private $auctionService;

    /**
     * @var RebiddingServiceInterface
     */
    private $rebiddingService;

    public function setTime(DateTime $requestDatetime, string $regNum, bool $isChangeTime): array
    {
        $procedureRepo = $this->procedureManager->getProcedureRepository();
        $lotRepo = $this->lotManager->getLotRepository();
        $procedure = $procedureRepo->findOneBy(['registrationNumber' => $regNum]);

        if (null === $procedure) {
            throw new InvalidArgumentException('Не найдена процедура по регистрационному номеру');
        }

        $lot = $lotRepo->findOneBy(['procedure' => $procedure]);

        if (null === $lot) {
            throw new InvalidArgumentException('Не найден лот');
        }

        /** @var TemplateInterface $template */
        $template = $lot->getTemplate();

        if (null === $template) {
            throw new InvalidArgumentException('Не найден шаблон процеуры.');
        }

        $brief = (int) $template->getTemplateDescription()->getBrief();

        try {
            $this->em->getConnection()->beginTransaction();

            switch ($brief) {
                case 3:
                    $auctionDateTime = $this->auctionService
                        ->setAuctionTime($lot, $requestDatetime->format('H'), $requestDatetime->format('i'), $isChangeTime);

                    if (null === $auctionDateTime) {
                        throw new InvalidArgumentException('Не удалось установить время проведения аукциона.');
                    }

                    $result = ['success' => true, 'auctionStartDateTime' => $auctionDateTime->format(DATE_ATOM)];
                    break;
                case 4:
                    $rebiddingDateTime = $this->rebiddingService
                        ->setRebiddingTime($lot, $requestDatetime->format('H'), $requestDatetime->format('i'), $isChangeTime);

                    if (null === $rebiddingDateTime) {
                        throw new InvalidArgumentException('Не удалось установить дату переторжки.');
                    }

                    $result = ['success' => true, 'rebiddingStartDateTime' => $rebiddingDateTime->format(DATE_ATOM)];
                    break;
                default:
                    throw new InvalidArgumentException('Тип процедуры не соответствует аукциону/конкурсу');
            }

            $this->em->persist($lot);
            $this->em->flush();
        } catch (Throwable $exception) {
            $this->em->getConnection()->rollBack();
//            throw $exception;
        }

        $this->em->getConnection()->commit();

        return $result;
    }
}


