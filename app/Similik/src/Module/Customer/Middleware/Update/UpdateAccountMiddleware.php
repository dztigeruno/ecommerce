<?php
/**
 * Copyright © Nguyen Huu The <thenguyen.dev@gmail.com>.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Similik\Module\Customer\Middleware\Update;


use function Similik\dispatch_event;
use Similik\Middleware\MiddlewareAbstract;
use Similik\Module\Graphql\Services\GraphqlExecutor;
use Similik\Services\Http\Request;
use Similik\Services\Http\Response;

class UpdateAccountMiddleware extends MiddlewareAbstract
{

    public function __invoke(Request $request, Response $response, $delegate = null)
    {
        $variables = $request->get('variables', []);
        $variables['customer']['customer_id'] = $request->attributes->get('id');

        $query = "mutation UpdateCustomer(\$customer: CustomerInput!) { updateCustomer (customer: \$customer) {status message customer {full_name email}}}";
        dispatch_event("filter_customer_update_query", [&$query]);

        $promise = $this->getContainer()
            ->get(GraphqlExecutor::class)
            ->waitToExecute([
                "query" => $query,
                "variables" => $variables
            ]);
        $promise->then(function($result) use ($request, $response) {
                if($result->errors)
                    throw new \Exception($result->errors[0]->message);

                if($result->data['updateCustomer']['status'] == false)
                    throw new \Exception($result->data['updateCustomer']['message']);
                $response->addData('customerUpdate', ['status'=> true]);
                $response->addState('customer', $result->data['updateCustomer']['customer'])->notNewPage();
            })->otherwise(function($reason) use($request, $response) {
                $response->addData('customerUpdate', ['status'=> false, 'message'=> $reason[0]['message']]);
            });

        return $promise;
    }
}