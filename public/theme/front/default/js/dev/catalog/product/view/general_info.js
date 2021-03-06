import Area from "../../../../../../../../js/production/area.js";

function Name({name}) {
    return <h1 className="product-single-name">{name}</h1>
}

function Sku({sku}) {
    return <div className="product-single-sku mb-1"><span>SKU</span><span>: </span>{sku}</div>
}

export default function GeneralInfo({name, short_description, sku, stock_availability}) {
    return <Area id="product_view_general_info" coreWidgets={[
        {
            'component': Name,
            'props': {
                name: name
            },
            'sort_order': 10,
            'id': 'product_single_name'
        },
        {
            'component': Sku,
            'props': {
                sku: sku
            },
            'sort_order': 20,
            'id': 'product_single_sku'
        },
        {
            'component': () => <div className="stock-availability"><span>Availability:</span>{stock_availability === 1 ? (<span className="text-success">In stock</span>) : (<span className="text-danger">Out of stock</span>)}</div>,
            'props': {},
            'sort_order': 30,
            'id': 'product_stock_availability'
        },
        {
            'component': () => <div className="product-short-description mt-4">{short_description}</div>,
            'props': {},
            'sort_order': 40,
            'id': 'product_short_description'
        }
    ]}/>
}