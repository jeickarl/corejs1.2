import { ApiProperty } from '@nestjs/swagger';

export class HealthDto {
  @ApiProperty({ example: 'corejs-backend' })
  service!: string;

  @ApiProperty({ example: 'ok' })
  status!: 'ok';
}

