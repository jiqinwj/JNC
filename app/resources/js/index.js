$(function () {

    let logic = {
        ws:null,
        connectionState:0,
        msgId:0,
        sendCmdData:function(controller,postData) {

            var jsonData = {
                cmd:controller,
                postData:postData,
                msgId:this.msgId++,
                time:Date.now()
            };
            window.ws.send(JSON.stringify(jsonData));
        },
        mqttMsgStatus:function (msg) {
            var oldV = $("#mqttRecv").val();
            $("#mqttRecv").val(oldV+msg);
            var message = document.getElementById('mqttRecv');
            message.scrollTop = message.scrollHeight;
        },
        websocketConnect:function (ip,port) {

            if (window.ws!=null&&window.ws.readyState==1){
                this.mqttMsgStatus("websocket服务已经启动请勿重复连接\r\n");
                return true;
            }
            if (!ip||!port){
                this.mqttMsgStatus("websocket连接参数不正确\r\n");
                return true;
            }
            let url = "ws://"+ip.trim()+":"+port.trim()+"/echo";
            this.ws = new WebSocket(url);
            window.ws = this.ws;
            console.log(url);

            ws.onopen = function()
            {
                $("#startMqtt").text("停用");
                $("#startMqtt").attr("data-v",2);
                logic.connectionState=1;
                logic.mqttMsgStatus("websocket成功连接服务器\r\n");
            };

            ws.onmessage = function (evt)
            {
                var received_msg = evt.data;
                //$("#mqttRecv").val("websocket收到服务器返回的数据："+received_msg+"\r\n");
                logic.mqttMsgStatus("websocket收到服务器返回的数据："+received_msg+"\r\n");
                var jsonMsg = JSON.parse(received_msg);

                if(jsonMsg.code==300){
                    var esp = jsonMsg['esp8266'];
                    console.log(esp);
                   // console.log(esp.d);
                    // if (esp.d=="led"){
                    //     $("#io_stat"+esp.p).text(esp.s==1?"已关闭":"已开启");
                    // }
                    switch (esp.d) {
                        case "led":
                            $("#io_stat"+esp.p).text(esp.s==1?"已关闭":"已开启");
                            break;
                        case "temp":
                           // console.log("temp===="+esp.s);
                            $("#temp").text(esp.s);
                            break;
                        case "dht":
                            console.log("temp===="+esp.s);
                            $("#dht").text(esp.s);
                            break;
                        case "adc":
                            $("#adc").text(esp.s+"V");
                            break;
                    }

                }
            };

            ws.onclose = function()
            {
                logic.connectionState=0;
                $("#startMqtt").text("启用");
                $("#startMqtt").attr("data-v",1);

                logic.mqttMsgStatus("websocket服务器已经断开连接\r\n");
            };
            ws.onerror = function () {

            }
        }
    };

    $("#startMqtt").click(function () {

        var ip = $("#serverIp").val();
        var port = $("#port").val();

        if (ip&&port){
            if ($(this).attr("data-v")==1){
                logic.websocketConnect(ip,port);
            } else{
                window.ws.close();
                $("#startMqtt").text("启用");
                $("#startMqtt").attr("data-v",1);
            }

        }else{
            logic.mqttMsgStatus("ip,port参数不可为空\r\n");
        }
    });

    $("#subscribe").click(function(){
        var clientId = $("#clientId").val();
        var userName = $("#userName").val();
        var password = $("#Password").val();
        var TopicName = $("#TopicName").val();
        logic.sendCmdData("mqtt/connect",{
            clientId:clientId,
            userName:userName?userName:"",
            password:password?password:"",
            device:"web",
            TopicName:TopicName
        });
    });


    //单片机相关GPIO端口控制
    $(".gpio").click(function (e) {

        let postData = new Object();

        postData['p'] = $(this).attr("data-id");
        var state = $(this).attr("data-state")==1?0:1;
        $(this).attr("data-state",state);
        postData['s'] = state;
        if(logic.connectionState==0){
            logic.mqttMsgStatus("服务器未连接请先连接\r\n");
            return false;
        }

        //$(this).parent().next("td").text((state==1?"关闭中":"开启中"));
        logic.sendCmdData("gpio/led",postData);

        window.selectDeviceNum = $(this).parent().next("td");

    });

    //OLED显示屏数据控制
    $("#sendData").click(function (e) {

        if(logic.connectionState==0){
            logic.mqttMsgStatus("服务器未连接请先连接\r\n");
            return false;
        }
        var info = $("#mqttSend").val();

        let postData = new Object();

        postData['info'] = info?info:"nothing";
        console.log(postData);
        logic.sendCmdData("oled/info",postData);
        $("#mqttSend").val("");


    })
    $("#adc_get").click(function (e) {

        if(logic.connectionState==0){
            logic.mqttMsgStatus("服务器未连接请先连接\r\n");
            return false;
        }
        let postData = new Object();
        postData['s'] = 0;
        postData['p'] = 0;
        logic.sendCmdData("adc/info",postData);
        $("#mqttSend").val("");


    })
});