import { ApiProperty } from '@nestjs/swagger';

export class ClientPortalConfigDto {
  @ApiProperty({ example: true })
  enableLookupById!: boolean;

  @ApiProperty({ example: true })
  showTimeline!: boolean;

  @ApiProperty({ example: true })
  allowApproval!: boolean;

  @ApiProperty({ example: 'Bienvenido' })
  homeTitle!: string;

  @ApiProperty({ example: 'Servicio técnico' })
  homeSubtitle!: string;

  @ApiProperty({ example: 'https://wa.me/573000000000' })
  whatsappLink!: string;

  @ApiProperty({ example: 'Calle 123 #45-67' })
  addressText!: string;

  @ApiProperty({ example: 'Lun-Vie 9-6' })
  hoursText!: string;

  @ApiProperty({ example: '<iframe ...></iframe>' })
  mapEmbedUrl!: string;
}

