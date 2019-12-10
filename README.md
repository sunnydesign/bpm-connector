# bpm-connector

POST request
```
POST https://bpm.kubia.dev/engine-rest/process-definition/key/process-connector/start
```

json payload
```json
{
  "variables": {
    "message": {
      "value": "{\"data\":{\"user\":{\"first_name\":\"John\",\"last_name\":\"Doe\"},\"account\":{\"number\":\"702-0124511\"},\"date_start\":\"2019-09-14\",\"date_end\":\"2019-10-15\"},\"headers\":{\"command\":\"createTransactionsReport\"}}",
      "type": "String"
    }
  }
}
```
