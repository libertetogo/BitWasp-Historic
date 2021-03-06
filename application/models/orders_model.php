<?php

class Orders_model extends CI_Model {
		
	public function __construct(){
		$this->load->library('my_session');
		$this->load->model('currency_model');
		$this->load->model('items_model');
	}

	// Progress the order from $fromStep to the next.
	public function nextStep($orderID,$fromStep){
		// Select the order from the table.
		$orderInfo = $this->getOrderByID($orderID);

		if($orderInfo === NULL){
			// If the order cannot be found, return NULL.
			return NULL;
		} else {
			// Check the orders current progress.
			$this->db->select('step');
			$this->db->where('id',$orderID);
			$query = $this->db->get('orders');
			$result = $query->result();

			// Check if the the step we'd like to move from matches the orders current progress.
			if($result[0]->step !== $fromStep){
				// Does not match, exit.
				return NULL;
			}

			// Update the order, incrementing the progress, and updating the last 
			$this->db->where('id',$orderID);
			$query = $this->db->update('orders',array(	'step' => ($result[0]->step+1),
								 	'time' => time(),
								)
						);

			// Check if the query was successful.
			if($query){
				return TRUE;
			} else {
				return FALSE;
			}
		}
	}

	// Return all orders made by a buyer, or restrict to a specific Vendor. 
	public function myOrders($sellerHash = NULL){
		$this->load->model('users_model');
		// Load the buyers hash.
		$buyer = $this->my_session->userdata('userHash');

		// Order the results by time, showing latest first.
		$this->db->order_by('time DESC');

		// If the sellerHash is provided, check the user exists.
		if($sellerHash !== NULL && $this->users_model->get_user(array('userHash' => $sellerHash)) !== FALSE){
			// If the vendor exists, select only orders from the buyer to the vendor.
			$getOrders = $this->db->get_where('orders',array('buyerHash' => $buyer,
									 'sellerHash' => $sellerHash)
							);
		} else {
			// Otherwise, slow all the buyers orders.
			$getOrders = $this->db->get_where('orders',array('buyerHash' => $buyer));
		}

		// Build up a nice formatted array containing all information for the orders.
		$orders = $this->buildOrderArray($getOrders);
		
		if($orders === FALSE){
			// No orders, return NULL.
			return NULL;
		} else {
			// Return the formatted array.
			return $orders;
		}
	}	

	// Build a full array of information about the order. 
	public function buildOrderArray($order){
		// This function takes the returned object after a get() or get_where() result from the DB
		// and builds a comprehensive array.

		// Check there is orders in the result.
		if($order->num_rows() > 0){
			$i = 0;
		
			// Loop through each order. 
			foreach($order->result() as $result){
				
				// Extract information about the item/quantity: {itemHash}-{quantity}:{itemHash}-{quantity}
				$tmp = $result->items;
				$items = explode(":", $tmp);
				$j = 0;
				
				// Loop through each entry
				foreach($items as $item){
					// Extract the itemHash and quantity.
					$array = explode("-", $item);
					
					$tmp = $this->items_model->getInfo($array[0]);
					
					// Check if the item still exists.
					if($tmp == NULL){
						$message = "Item {$array[0]} ";
						if(strtolower($this->my_session->userdata('userRole')) == "vendor"){
							$message .= "<br /> has been removed";
						} else {
							$message .= "has been <br /> removed. Contact your vendor.";
						}

						// Not found; Return an error array.
						$itemInfo[$j] = array(	'itemHash'=>'removed',
									'name' => $message);
					} else {
						// Item found; return information about it.
						$itemInfo[$j] = $tmp;
					}
					// Add the ordered quantity into the array.
					$itemInfo[$j++]['quantity'] = $array[1];
				}

				// Format the value for the progress of the order.
				if($result->step == '0'){
					// Order being filled by buyer, yet to be placed.
					$stepMessage = anchor('order/place/'.$result->sellerHash,'Place Order');

				} else if($result->step == '1'){
					// Order placed, vendor is awaiting payment.
					$stepMessage = 'Vendor awaiting payment.';

				} else if($result->step == '2'){
					// Vendor confirmed payment received. Awaiting dispatch by Vendor.
					$stepMessage = 'Awaiting dispatch.';

				} else if($result->step == '3'){
					// Vendor has dispatched item. Allow Vendor be reviewed.
					$stepMessage = "Completed. ".anchor('orders/review/'.$result->id,'Please Review');
				} else {//Error..
					$stepMessage = 'Error!';
				}

				// Add the next order to the array.
				$orders[$i++] = array(	'id' => $result->id,
							// Array containing info about the seller.
							'seller' => $this->users_model->get_user(array('userHash'=> $result->sellerHash)),
							// Array containing info about the buyer.
							'buyer' => $this->users_model->get_user(array('userHash'=> $result->buyerHash)),
							// Total price of the order.
							'totalPrice' => $result->totalPrice,

							// Currency identifier, and symbol
							'currency' => $result->currency,
							'currencySymbol' => $this->currency_model->get_symbol($result->currency),

							// Load the timestamp, and formatted time, for the order.
							'time' => $result->time,
							'dispTime' => $this->general->displayTime($result->time),

							// Add the array of info for the items in the order.
							'items' => $itemInfo,

							// Add progress number, and formatted progress indicator to the array.
							'step' => $result->step,
							'progress' => $stepMessage );
				// Unset the itemInfo variable to avoid carrying on previous information throughout other orders.
				unset($itemInfo);
			}
			// Return the built response.
			return $orders;
		} else {

			// No orders in the request.
			return FALSE;
		}
	}

	// Load orders to the seller at particular point in the order process.
	public function ordersByStep($userID,$step){
		// Load orders with specified sellerHash and step.
		$query = $this->db->get_where('orders', array(	'sellerHash' => $userID,
							  	'step' => $step ) );

		// Format the returned response.
		$orders = $this->buildOrderArray($query);

		if($orders === FALSE){
			// Check if the response is empty.
			return NULL;
		} else {
			// Return the formatted array about the orders.
			return $orders;
		}
	}

	// Load orders between a buyer and seller, optionally specifying a particular step in the order process.
	public function check($buyer,$seller,$step=NULL){
		//Check there is no ongoing orders between this buyer and vendor
		if($step == NULL){
			$key = 'step !=';
			$val = 3;
		} else {
			$key = 'step';
			$val = $step;
		}

		// Load the orders by buyer/vendor combination.
		$query = $this->db->get_where('orders',array(	'buyerHash' => $buyer,
								'sellerHash' => $seller,
								$key => $val));

		// If the result has entries, return the formatted array.
		if($query->num_rows() > 0){
			return $this->buildOrderArray($query);
		} else {
			// Otherwise, return NULL.
			return NULL;
		}
	}

	// Add the array of information to the orders table.
	public function createOrder($orderInfo){
		$query = $this->db->insert('orders',$orderInfo);

		// Check the submission was successful.
		if($query){ 
			return TRUE;
		} else {
			return FALSE;
		}
	}
	
	public function getOrderByID($id){
		// Load orders by numeric ID. 
		$query = $this->db->get_where('orders', array('id' => $id));
		
		// If there are orders in the result, return a formatted response.
		if($query->num_rows() > 0){
			return $this->buildOrderArray($query);
		} else {
			// Otherwise return NULL.
			return NULL;
		}
	}

	public function updateOrder($newInfo){
		// Get current order
		$currentOrder = $this->getOrderByID($newInfo['id']);
		
		// Build a list of new items.
		$found = false;			// Is the item currently in the order?
		$newItems = '';			
		$place = 0;			// Placeholder for formatting.

		if(count($currentOrder)>0){
			// Loop through each item in the order
			foreach($currentOrder[0]['items'] as $item){
				
				// Check if the item is found on the list.
				if($item['itemHash'] == $newInfo['itemHash']){
					$quantity = ($newInfo['quantity']);
					$found = true;
				} else {
					$quantity = $item['quantity'];
				}
	
				// Check the submitted quantity is greater than 0. 
				if($quantity > 0){

					// Check if the current item needs a ':' to separate the entries.
					if($place++ !== 0)
						$newItems .= ":";

					// Add the itemHash and quantity.
					$newItems.= $item['itemHash']."-".$quantity;
				}
				
			}
			// Finish off new items, add the item if it's not already held in the order.
			if($found === false){
				// If the quantity is greater than zero, add the new value to the string.
				if($newInfo['quantity'] > 0)
					$newItems.= ":".$newInfo['itemHash']."-".$newInfo['quantity'];
			}
	
			// If the newItems is not empty, update the order.
			if(!empty($newItems)){
				// Regenerate the total price.
				$splitNewItems = explode(":",$newItems);
				$totalPrice = 0;
				foreach($splitNewItems as $item){
					// Loop through items, adding up the price of the items. 
					$info = explode("-",$item);
					$quantity = $info[1];
					$itemInfo = $this->items_model->getInfo($info[0]);
					$totalPrice += $quantity*$itemInfo['price'];
				}	

				// Build the array to update the table with.
				$order = array( 'items' => $newItems,
						'totalPrice' => $totalPrice,
						'time' => time() );
	
				// Select the order by ID, and make sure it's still at step=0.
				$this->db->where(	array(	'id'	=> $currentOrder[0]['id'],
								'step'	=> '0'));
				
				// Check whether the update was successful.
				if($this->db->update('orders',$order)){
					return TRUE;
				} else {
					// Return false if the query was unsuccessful.
					return FALSE;
				}
			} else {
				$this->db->where('id',$currentOrder[0]['id']);
				if($this->db->delete('orders')){
					return 'DROP';
				}
			}
		}
		return NULL;
	}

	// Submit a review for an Item/Vendor.
	public function review($review,$type){

		// Build an array to insert into the tables.
		$reviewArray = array(	'reviewedID' => $review['reviewedID'],
					'reviewType' => $type,
					'reviewText' => $review['comments'],
					'rating' => $review['rating'],
					'time' => time());

		// Check if the query was successful.
		if($this->db->insert('reviews',$reviewArray)){

			// Query successful; if the review is for a Vendor, delete the order from the table.
			if($type == 'Vendor'){
				// Delete from orders table
				$this->db->where('id',$review['orderID']);

				// Check if the order has been deleted. 
				if($this->db->delete('orders')){
					// Return TRUE if successful.
					return TRUE;
				} else {
					// Unsuccesful, return FALSE;
					return FALSE;
				}
			}

		} else {
			// Query was unsuccessful.
			return FALSE;
		}
	}

	public function listReviews($listReviews){
		// Set default value for the number of reviews to display.
		$count = 5;

		// If there is a specified amount of reviews to display, change the number to this.
		if(isset($listReviews['count']) && is_numeric($listReviews['count']))
			$count = $listReviews['count'];

		// Order results by time, and set the appropriate LIMIT.
		$this->db->order_by("time LIMIT $count");

		// Load reviews by the submitted ID.
		$query = $this->db->get_where('reviews',array(	'reviewedID'=>$listReviews['reviewedID'])
					);

		// If there are reviews in the response.
		if($query->num_rows() > 0){
			$reviews = array();
			$rating = 0;

			// Loop through each entry, and add the required fields to an array.
			// This returns the latest information, based on the $count.
			foreach($query->result() as $review){
				array_push($reviews,array(	'reviewedID' => $review->reviewedID,
								'rating' => $review->rating,
								'reviewText' => $review->reviewText,
								'time' => $this->general->displayTime($review->time),
							)
					);
			}

			/* Quickfix ahead! This will be expanded to take better account of the rating */
			// Load everything to work out the weighted rating.
			$this->db->order_by('time');
			$allratings = $this->db->get_where('reviews',array('reviewedID'=>$listReviews['reviewedID']));
			foreach($allratings->result() as $tmp){
				$rating += $tmp->rating;
			}
			$rating = ($rating/$allratings->num_rows());
			/* End of quick-fix*/


			$results = array('AvgRating' => $rating,
					 'reviews'   => $reviews );

			// Return the reviews array.
			return $results;
		} else {
			// Otherwise return NULL.
			return NULL;
		}
	}


	public function getQuantity($itemHash){
		
		// Determine buyerHash and sellerHash
		$buyerHash = $this->my_session->userdata('userHash');
		$itemInfo = $this->items_model->getInfo($itemHash);
		$sellerHash = $itemInfo['sellerID'];

		// Load order information
		$getOrder = $this->check($buyerHash,$sellerHash);
	
		// Loop through the items
		foreach($getOrder[0]['items'] as $item){
			// If the item is there, return the associated quantity.
			if($item['itemHash'] == $itemHash){
				return $item['quantity'];
			}
		}
	
		// If the item hasn't been found, return NULL.
		return NULL;
	}

	
};


