<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

use App\Events\SluggableCreating;
use App\GASModel;
use App\SluggableID;
use App\Booking;
use App\BookedProduct;

class Product extends Model
{
    use GASModel, SluggableID;

    public $incrementing = false;

    protected $events = [
        'creating' => SluggableCreating::class,
    ];

    public function category()
    {
        return $this->belongsTo('App\Category');
    }

    public function measure()
    {
        return $this->belongsTo('App\Measure');
    }

    public function supplier()
    {
        return $this->belongsTo('App\Supplier');
    }

    public function orders()
    {
        return $this->belongsToMany('App\Order');
    }

    public function variants()
    {
        return $this->hasMany('App\Variant')->with('values')->orderBy('name', 'asc');
    }

    public function getSlugID()
    {
        $append = '';
        $index = 1;
        $classname = get_class($this);

        while(true) {
            $slug = sprintf('%s::%s', $this->supplier_id, str_slug($this->name)) . $append;
            if (Product::where('id', $slug)->first() != null) {
                $append = '_' . $index;
                $index++;
            }
            else {
                break;
            }
        }

        return $slug;
    }

    public function stillAvailable($order)
    {
        if ($this->max_available == 0) {
            return 0;
        }

        $quantity = BookedProduct::where('product_id', '=', $this->id)->whereHas('booking', function ($query) use ($order) {
            $query->where('order_id', '=', $order->id);
        })->sum('quantity');

        if ($this->portion_quantity != 0)
            $quantity *= $this->portion_quantity;

        return $this->max_available - $quantity;
    }

    public function bookingsInOrder($order)
    {
        $id = $this->id;

        return Booking::where('order_id', '=', $order->id)->whereHas('products', function ($query) use ($id) {
            $query->where('product_id', '=', $id);
        })->get();
    }

    public function printablePrice($order)
    {
        $price = $this->contextualPrice($order, false);

        if (!empty($this->transport) && $this->transport != 0) {
            $str = sprintf('%.02f € / %s + %.02f € trasporto', $price, $this->measure->name, $this->transport);
        } else {
            $str = sprintf('%.02f € / %s', $price, $this->measure->name);
        }

        if ($this->variable) {
            $str .= '<small> (prodotto a prezzo variabile)</small>';
        }

        return $str;
    }

    /*
        Attenzione: questo non tiene conto dell'eventuale sconto
        applicato sull'ordine in cui il prodotto si trova
    */
    public function getDiscountPriceAttribute()
    {
        if (empty($this->discount)) {
            return $this->price;
        } else {
            return applyPercentage($this->price, $this->discount);
        }
    }

    /*
        Questo è per determinare il prezzo del prodotto in un dato contesto,
        ovvero in un ordine. I casi possibili sono:
        - se lo sconto del singolo prodotto è stato abilitato per l'ordine,
          viene applicato. Altrimenti resta il prezzo di riferimento
        - se l'ordine ha uno sconto, viene a sua volta applicato

        Per i prodotti con pezzatura, ritorna già il prezzo per singola unità
        e non è dunque necessario normalizzare ulteriormente
    */
    public function contextualPrice($order, $rectify = true)
    {
        /*
            Attenzione: hasProduct() altera il riferimento a $product,
            popolandolo con i dati pescati dal database in relazione all'ordine
            desiderato.
            Ma $this potrebbe non essere una copia genuina del prodotto, in
            quanto i suoi parametri possono essere temporaneamente sovrascritti
            (cfr. OrderController::recalculate), e non si vuole che essi siano
            riletti dal database.
            Sicché, a hasProduct passo una copia e preservo l'originale
        */
        $product = $this->replicate();
        $enabled = $order->hasProduct($product);

        if ($enabled && $product->pivot->discount_enabled) {
            $price = applyPercentage($this->price, $this->discount);
        } else {
            $price = $this->price;
        }

        $price = applyPercentage($price, $order->discount);

        if ($rectify && $product->portion_quantity != 0)
            $price = $price * $product->portion_quantity;

        return $price;
    }

    public function printableMeasure($verbose = false)
    {
        if ($this->portion_quantity != 0) {
            if ($verbose)
                return sprintf('Pezzi da %.02f %s', $this->portion_quantity, $this->measure->name);
            else
                return sprintf('%.02f %s', $this->portion_quantity, $this->measure->name);
        }
        else {
            $m = $this->measure;
            if ($m == null) {
                return '';
            } else {
                return $m->name;
            }
        }
    }

    public function printableDetails($order)
    {
        $details = [];

        if ($this->min_quantity != 0) {
            $details[] = sprintf('Minimo: %.02f', $this->min_quantity);
        }
        if ($this->max_quantity != 0) {
            $details[] = sprintf('Massimo Consigliato: %.02f', $this->max_quantity);
        }
        if ($this->max_available != 0) {
            $details[] = sprintf('Disponibile: %.02f (%.02f totale)', $this->stillAvailable($order), $this->max_available);
        }
        if ($this->multiple != 0) {
            $details[] = sprintf('Multiplo: %.02f', $this->multiple);
        }

        return implode(', ', $details);
    }
}
