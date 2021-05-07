import Order from '../PageObjects/objects'




describe ('Magento2', function(){

    it('CC Payment', function(){

        const ord = new Order()
        ord.clrcookies()
        ord.visit()
        ord.signin()
        ord.addproduct()
        ord.cc_payment()
        ord.admin()
        ord.capture()
    })

    it('Klarna Payment', function(){

        const ord = new Order()
        ord.clrcookies()
        ord.visit()
        ord.signin()
        ord.addproduct()
        ord.klarna_payment()
        ord.admin()
        ord.capture()
    })

    it('Subscription', function(){

        const ord = new Order()
        ord.clrcookies()
        ord.visit()
        ord.signin()
        ord.subscription_product()
        ord.subscription_payment()
        ord.admin()
        ord.capture_subscription()

    })

})