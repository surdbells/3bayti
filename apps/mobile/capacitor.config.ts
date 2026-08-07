import type { CapacitorConfig } from '@capacitor/cli';

const config: CapacitorConfig = {
  appId: 'com.threebayti.app',
  appName: '3bayti',
  webDir: 'www',
  plugins: {
    PushNotifications: {
      presentationOptions: [
        'badge',
        'sound',
        'alert'
      ]
    },
    FirebaseAuthentication: {
      skipNativeAuth: false,
      providers: [
        'google.com',
        'apple.com'
      ]
    },
    CapacitorUpdater: {
      autoUpdate: true,
      directUpdate: false,
      resetWhenUpdate: true,
      appReadyTimeout: 10000,
      updateUrl: 'https://api-v3.3bayti.ae/v3/ota/updates',
      publicKey: '-----BEGIN RSA PUBLIC KEY-----\nMIIBCgKCAQEAv8en4vDVMl0KVn2XcsMQYGj3WRTFtVe8ZODAJ0O0kuU9l+Sc4Onm\npJWQL0tXGDIcgXylJv8dNujzaF7y6WJzBb49VaSjMVAUqFsVrYorBxgVM2E53apG\nCpSiy4WzL/JI9JJ3F69xyclSf9k9f0gu6NKXA+70oJ03ms40YjQpObwfMdOx36kx\nvxYVmAzaLizkNWPIpNNteH2vw/AbFnGHL4QKZZiKFspsCdfqrEWbFjaVwZDT/+3q\nd0LW7tC++re+VoqYIHApu6ILIQCwdrvDGBvk6sBsN3t+9ZWxgqBppV5ou0I4yN73\nI4aaJyz/wCyOUE+z7/gTNqOAU20cl5XfnwIDAQAB\n-----END RSA PUBLIC KEY-----\n'
    },
    SplashScreen: {
      launchShowDuration: 0,
      launchAutoHide: false,
      backgroundColor: '#faf8f5',
      androidScaleType: 'CENTER',
      showSpinner: false,
      splashImmersive: false,
      splashFullScreen: false
    }
  },
  ios: {
    scrollEnabled: false,
    webContentsDebuggingEnabled: true
  }
};

export default config;
