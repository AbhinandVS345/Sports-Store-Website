<?php
include 'connection.php';
session_start();

$categories = isset($_POST['categories']) ? json_decode($_POST['categories']) : [];
$sports = isset($_POST['sports']) ? json_decode($_POST['sports']) : [];
$brands = isset($_POST['brands']) ? json_decode($_POST['brands']) : [];
$searchTerm = isset($_POST['search']) ? trim($_POST['search']) : '';
$priceRanges = isset($_POST['prices']) ? json_decode($_POST['prices'], true) : [];

// Build the query
$query = "SELECT p.*, c.name as category, s.name as sports, b.name as brand FROM product p 
            INNER JOIN category c ON p.category_id = c.category_id
            INNER JOIN sports s ON p.sports_id = s.sports_id 
            INNER JOIN brand b ON p.brand_id = b.brand_id 
            WHERE 1";

// Filter categories if "All" is not selected
if (!empty($categories) && !in_array("all", $categories)) {
    $categoriesList = implode(",", array_map('intval', $categories));
    $query .= " AND p.category_id IN ($categoriesList)";
}

// Filter sports if "All" is not selected
if (!empty($sports) && !in_array("all", $sports)) {
    $sportsList = implode(",", array_map('intval', $sports));
    $query .= " AND p.sports_id IN ($sportsList)";
}

// Filter brands if "All" is not selected
if (!empty($brands) && !in_array("all", $brands)) {
    $brandsList = implode(",", array_map('intval', $brands));
    $query .= " AND p.brand_id IN ($brandsList)";
}

// Filter by search term if provided
if (!empty($searchTerm)) {
    $searchTerm = $conn->real_escape_string($searchTerm); // Prevent SQL injection
    $query .= " AND (p.name LIKE '%$searchTerm%' OR p.description LIKE '%$searchTerm%')";
}

// Filter by price range if provided
if (!empty($priceRanges)) {
    // If "All" is selected, skip the price filter
    if (count($priceRanges) === 1 && $priceRanges[0] === "all") {
        // No price filter applied
    } else {
        $priceConditions = [];
        foreach ($priceRanges as $range) {
            if (isset($range['minPrice']) && isset($range['maxPrice'])) {
                $minPrice = intval($range['minPrice']);
                $maxPrice = $range['maxPrice'] === "above" ? null : intval($range['maxPrice']);

                if ($maxPrice === null) {
                    // Handle "Above ₹2000" case
                    $priceConditions[] = "p.price > $minPrice";
                } else {
                    // Handle normal range
                    $priceConditions[] = "p.price BETWEEN $minPrice AND $maxPrice";
                }
            }
        }
        if (!empty($priceConditions)) {
            $query .= " AND (" . implode(" OR ", $priceConditions) . ")";
        }
    }
}


$result = $conn->query($query);

if (mysqli_num_rows($result) > 0) {
    // Output the products
    while ($row = mysqli_fetch_array($result)) { ?>
        <div class="col-lg-4 col-sm-6 col-md-4 p-b-35">
            <a href="cust_singleproduct.php?id=<?php echo $row['product_id']; ?>">
                <?php $product_id = $row['product_id']; ?>
                <div class="single_product_item">
                    <div class="single_product_item_thumb">
                        <img src="./uploads/<?php echo $row['image']; ?>" alt="#" class="img-fluid">
                    </div>
                    <div class="d-flex justify-content-between">
                        <h4 class="txt-overflow"> <?php echo $row['name']; ?> </h4>
                        <?php
                        $cust_id = $_SESSION['userid'] ?? '';
                        $in_wishlist = false;

                        if ($cust_id) {

                            $check_query = "SELECT * FROM wishlist WHERE product_id = ? AND customer_id = ?";
                            $stmt = $conn->prepare($check_query);
                            $stmt->bind_param('ii', $product_id, $cust_id);
                            $stmt->execute();
                            $ans = $stmt->get_result();

                            $in_wishlist = $ans->num_rows > 0; // true if product is in wishlist
                        }


                        $color_class = $in_wishlist ? 'text-danger' : 'text-muted'; // red if in wishlist, gray if not
                        ?>
                        <?php if ($in_wishlist) { ?>
                            <a href="#" style="font-size: 20px;" class="like_us wishlist-btn <?php echo $color_class; ?>" data-product-id="<?php echo $product_id; ?>"> <i class="fa-solid fa-heart"></i> </a>
                        <?php } else { ?>
                            <a href="#" style="font-size: 20px;" class="like_us wishlist-btn <?php echo $color_class; ?>" data-product-id="<?php echo $product_id; ?>"> <i class="fa-regular fa-heart"></i> </a>
                        <?php } ?>
                    </div>
                    <?php $product_id = $row['product_id']; ?>
                    <h4><i class="fa-solid fa-indian-rupee-sign"></i> <?php echo $row['price']; ?></h4>
                    <?php
                    $sql2 = "SELECT COUNT(*) FROM single_product WHERE product_id=$product_id";
                    $result2 = mysqli_query($conn, $sql2);
                    $row2 = mysqli_fetch_row($result2);
                    $count = $row2[0];
                    if ($count == 1) {
                        $sql3 = "SELECT single_product_id FROM single_product WHERE product_id=$product_id";
                        $result3 = mysqli_query($conn, $sql3);
                        $row3 = mysqli_fetch_row($result3);
                        $single_id = $row3[0];
                    ?>
                        <!-- Button for making the AJAX call directly -->
                        <a href="#" data-single-product-id="<?php echo $single_id; ?>" class="add-to-cart genric-btn primary-border radius col-md-12" style="font-size: 15px;">
                            Add to cart
                        </a>
                    <?php } else { ?>
                        <!-- Button that opens the modal and passes the product ID -->
                        <a href="#" data-bs-toggle="modal" data-bs-target="#exampleModal" data-product-id="<?php echo $product_id; ?>" class="open-size-modal genric-btn primary-border radius col-md-12" style="font-size: 15px;">
                            Add to cart
                        </a>
                    <?php } ?>
                </div>
            </a>
        </div>
    <?php }
} else { ?>
    <div class="col-md-12 text-center">
        <h3>No products match your filters. Try adjusting the criteria.</h3>
    </div>
<?php }
?>

<!-- Add to cart -->
<script>
    $(document).ready(function() {
        // Declare variables in a higher scope
        let selectedSizeId = null;
        let selectedProductId = null;
        // Click event handler for the modal button
        $('.open-size-modal').click(function() {
            var productId = $(this).data('product-id'); // Get the product ID
            $('#size_prod_id').val(productId); // Assign it to the hidden field in the modal

            // Clear previous sizes
            $('#size-options').html('');

            // Make AJAX request to get sizes
            $.ajax({
                url: 'get_sizes.php',
                type: 'GET',
                data: {
                    product_id: productId
                },
                success: function(response) {
                    // Inject the response (size options) into the size-options div
                    $('#size-options').html(response);
                },
                error: function(xhr, status, error) {
                    console.error("An error occurred:", status, error);
                }
            });
        });

        $(document).on('click', '.size-option', function() {
            var selectedSizeId = $(this).data('size-id');
            var selectedProductId = $(this).data('single-product-id');
            $('.size-option').removeClass('selected');
            $(this).addClass('selected');
            $('#selected_size_id').val(selectedSizeId); // Update hidden input with selected size
            $('#selected_single_id').val(selectedProductId); // Update hidden input with selected product
        });

        // // Handle the "Add to Cart" button click with size
        // $(document).on('click', '.add-to-cart-size', function(event) {
        //     event.preventDefault();
        //     var selectedSizeId = document.querySelector('#selected_size_id').getAttribute('value'); // Get the size ID
        //     var selectedProductId = document.querySelector('#selected_single_id').getAttribute('value'); // Get the product ID

        //     // Check if a size is selected
        //     if (!selectedSizeId) {
        //         Toastify({
        //             text: "Please select a size before adding to cart.",
        //             duration: 3000,
        //             backgroundColor: "#FF5F6D",
        //             className: "custom-toast",
        //         }).showToast();
        //         return; // Exit if no size is selected
        //     }

        //     // AJAX call to add the product to the cart with size
        //     $.ajax({
        //         url: 'cust_cart.php',
        //         type: 'POST',
        //         data: {
        //             singleproduct_id: selectedProductId
        //         },
        //         dataType: 'json',
        //         success: function(response) {
        //             if (response.status == 'notLoggedIn') {
        //                 // Redirect to login page
        //                 window.location.href = 'login.php'; // Change to your login page URL
        //             } else {
        //                 handleCartResponse(response);
        //                 updateCartCount();
        //             }
        //         },
        //         error: function(xhr, status, error) {
        //             console.error("An error occurred:", status, error);
        //         }
        //     });
        // });

        // // Handle the "Add to Cart" button click (no size selection)
        // $(document).on('click', '.add-to-cart', function(event) {
        //     event.preventDefault();

        //     selectedProductId = $(this).data('single-product-id');

        //     // AJAX call to add the product to the cart
        //     $.ajax({
        //         url: 'cust_cart.php',
        //         type: 'POST',
        //         data: {
        //             singleproduct_id: selectedProductId
        //         },
        //         dataType: 'json',
        //         success: function(response) {
        //             if (response.status == 'notLoggedIn') {
        //                 // Redirect to login page
        //                 window.location.href = 'login.php'; // Change to your login page URL
        //             } else {
        //                 handleCartResponse(response);
        //                 updateCartCount();
        //             }
        //         },
        //         error: function(xhr, status, error) {
        //             console.error("An error occurred:", status, error);
        //         }
        //     });
        // });

        function updateCartCount() {
            $.ajax({
                url: 'get_cart_count.php', // Separate PHP file to get cart count
                type: 'GET',
                dataType: 'json',
                success: function(response) {
                    if (response.status === 'success') {
                        $('#cart-count').text(response.cartCount); // Update cart count element
                    }
                },
                error: function(xhr, status, error) {
                    console.log('Error fetching cart count:', error);
                }
            });
        }

        // Helper function to handle the response
        function handleCartResponse(response) {
            if (response.status === 'success') {
                Toastify({
                    text: "Product successfully added to cart. <u><a href='cust_viewcart.php' style='color: white;'>Go to Cart</a></u>",
                    duration: 5000,
                    close: true,
                    gravity: "bottom",
                    position: 'right',
                    backgroundColor: "#17191c",
                    stopOnFocus: true,
                    escapeMarkup: false,
                    className: "custom-toast",
                }).showToast();
            } else if (response.status === 'error') {
                Toastify({
                    text: "Failed to add product to cart.",
                    duration: 5000,
                    close: true,
                    gravity: "bottom",
                    position: 'right',
                    backgroundColor: "#17191c",
                    stopOnFocus: true,
                    escapeMarkup: false,
                    className: "custom-toast",
                }).showToast();
            } else if (response.status === 'outOfStock') {
                Toastify({
                    text: "Product is out of stock.",
                    duration: 5000,
                    close: true,
                    gravity: "bottom",
                    position: 'right',
                    backgroundColor: "#17191c",
                    stopOnFocus: true,
                    escapeMarkup: false,
                    className: "custom-toast",
                }).showToast();
            }
        }
    });
</script>

<!-- Wishlist -->
<script>
    var isLoggedIn = <?php echo isset($_SESSION['userid']) ? 'true' : 'false'; ?>;

    $(document).ready(function() {
        $('.wishlist-btn').on('click', function(e) {
            e.preventDefault();

            var button = $(this);
            var productId = button.data('product-id');
            var heartIcon = button.find('i');

            $.ajax({
                url: 'wishlist.php',
                type: 'POST',
                data: {
                    product_id: productId
                },
                success: function(response) {
                    console.log("Raw response:", response); // Log the raw response

                    if (typeof response === 'string') {
                        try {
                            response = JSON.parse(response); // Ensure response is parsed as JSON
                        } catch (e) {
                            console.error("Failed to parse JSON:", e);
                            toastr.error('An unexpected error occurred while processing the response.');
                            return;
                        }
                    }

                    if (response.status === 'success') {
                        if (response.action === 'added') {
                            heartIcon.removeClass('fa-regular').addClass('fa-solid');
                            heartIcon.removeClass('text-muted').addClass('text-danger');
                            toastr.success(response.message);
                        } else if (response.action === 'removed') {
                            heartIcon.removeClass('fa-solid').addClass('fa-regular');
                            heartIcon.removeClass('text-danger').addClass('text-muted');
                            toastr.success(response.message);
                        }

                        // Update the wishlist count in the icon
                        var wishlistCount = parseInt(response.wishlistCount, 10);
                        if (wishlistCount > 0) {
                            $('#wishlist-count').text(wishlistCount).show(); // Update and show cart count element
                        } else {
                            $('#wishlist-count').hide(); // Hide if no items
                        }
                    } else if (response.status === 'error') {
                        toastr.error(response.message); // Show error message
                    }
                },
                error: function() {
                    toastr.error('An unexpected error occurred.');
                }
            });
        });
    });
</script>