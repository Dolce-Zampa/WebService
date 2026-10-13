# Facebook authentication through Amazon Cognito

The storefront sends customers to the Cognito Hosted UI with the `Facebook` identity provider. Cognito redirects to the storefront callback, which sends the authorization code to the WebService. The WebService validates the one-time OAuth state, exchanges the code with Cognito, then creates or links the PrestaShop customer by Cognito `sub`.

## Required Cognito configuration

1. Create or enable the Facebook identity provider in the target Cognito User Pool and enter the Facebook App ID and App Secret there.
2. Add the Hosted UI app client callback URL, for example `https://www.dolcezampa.com/auth/facebook/callback`.
3. Enable the authorization-code grant and allow the `openid`, `email`, and `profile` scopes.
4. Configure the deployed WebService with `AWS_COGNITO_DOMAIN` (the Hosted UI domain, such as `store.auth.eu-west-1.amazoncognito.com`) and the exact same `AWS_COGNITO_REDIRECT_URI` callback URL.

Do not expose the Facebook App Secret or Cognito client secret in frontend environment variables.
